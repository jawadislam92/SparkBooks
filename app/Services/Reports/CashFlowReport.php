<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Enums\CashFlowClass;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Statement of Cash Flows, indirect method (spec seed §2, Phase 7 Task 4).
 * Reads exclusively through LedgerBalances + a direct journal_lines scan
 * (PROJECT_MAP §9 rule 9 — reports derive from journal_lines, never document
 * totals); performs NO ledger writes.
 *
 * TWO ENGINES THAT CHECK EACH OTHER (the whole point of this report):
 *
 * ENGINE A — direct cash-leg classification (`classifyCashLedger()`):
 * classifies every journal_line posted in the period, by the CLASS of the
 * OTHER lines in its own journal_entry. For a balanced entry, "cash moved
 * this much" (Σ debit−credit over its cash-pool lines) is ALWAYS exactly
 * equal to "everything else moved this much the other way" (Σ credit−debit
 * over its non-cash lines) — that is just double-entry, not an assumption —
 * so summing contribution = credit−debit per NON-CASH line, bucketed by that
 * line's account class, reconstructs the period's Operating/Investing/
 * Financing/FX cash flows WITHOUT ever needing to look at the cash line
 * itself. A transfer between two cash-pool accounts (both lines cash, zero
 * non-cash lines) contributes nothing to any bucket — nets to zero
 * automatically, no special-case needed.
 *
 * ENGINE B — indirect presentation (`buildIndirectOperating()`): starts from
 * Net Income (identical to ProfitAndLossReport's own net_profit — the same
 * LedgerBalances::movement() call, so the two can never disagree), then:
 *   + non-cash reclass (`add_backs`): any EXPENSE or INCOME account whose
 *     `cf_class` is Investing or Financing (instead of the ordinary
 *     Operating every P&L account gets by default) is pulled OUT of
 *     Operating by exactly its own NI contribution. THE DEPRECIATION
 *     ADD-BACK RULE: this is how a depreciation/amortization EXPENSE account
 *     (Dr Depreciation Expense / Cr Accumulated Depreciation — an entry that
 *     never touches a cash-pool account, so Engine A never even sees it) get
 *     added back — by its OWN movement, never accumulated depreciation's
 *     (accumulated depreciation is never assigned `cf_class = operating`, so
 *     it never enters the working-capital loop below either; its movement
 *     plays no role in this computation at all).
 *   + FX reclass (`fx_reclass`): FX Gain (4950) / FX Loss (5950) — special-
 *     cased by CODE, exactly like BalanceSheetReport special-cases 3200 —
 *     are pulled OUT of Operating by their combined NI contribution and
 *     surfaced on their own "Effect of FX on cash" line instead (POLICY
 *     DEFAULT: SparkBooks has no foreign-currency cash accounts / unrealized
 *     translation yet — see PROJECT_MAP §7 — so this line's only source
 *     today is the REALIZED settlement gain/loss on an FX invoice payment;
 *     confirm with the accountant whether that should instead stay inside
 *     Operating once real foreign-currency bank accounts exist).
 *   ± working-capital deltas: every Asset/Liability/Equity account whose
 *     `cf_class` is Operating (AR, prepaid, AP, accrued, deferred revenue,
 *     tax payable, ...) contributes `-movement` (a liability/AR-decrease
 *     frees cash; an asset/AR-increase ties it up) — the exact same
 *     `credit - debit` formula Engine A uses per line, applied here to the
 *     account's WHOLE-PERIOD movement instead of per-entry.
 *
 * assertCFO_indirect == CFO_direct (assertion 3, the strongest cross-check;
 * see CashFlowReportTest) holds because both engines fold to the SAME
 * underlying per-line `credit - debit` arithmetic, just grouped two
 * different ways (by entry vs. by account-over-the-whole-period) — see this
 * class's own test file for the worked proof on a mixed period.
 *
 * Investing/Financing in the FINAL statement are always Engine A's direct
 * figures (standard practice: only Operating has two competing derivations
 * to reconcile — Investing/Financing are actual cash flows either way).
 *
 * RETAINED EARNINGS (3200) is special-cased by CODE the same way
 * BalanceSheetReport special-cases it: its own raw movement is never fed
 * through the generic per-account loops above. A cash-touching entry that
 * posts directly to 3200 (a manual prior-period adjustment, or a dividend)
 * is classified Financing by Engine A — NI already excludes it (3200 is an
 * Equity account, never Income/Expense), so there is nothing to reclass out
 * of Operating for this case.
 */
class CashFlowReport
{
    /** Special-cased by CODE — never fed through the generic per-account cf_class loops (see class docblock). */
    private const RETAINED_EARNINGS_CODE = '3200';

    /** FX Gain / FX Loss — special-cased by CODE, routed to the dedicated "Effect of FX on cash" line. */
    private const FX_CODES = ['4950', '5950'];

    public function __construct(private LedgerBalances $ledger) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     operating: array{
     *         net_income: int,
     *         add_backs: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *         fx_reclass: int,
     *         working_capital: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *         total: int,
     *     },
     *     investing: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *     financing: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *     fx_on_cash: int,
     *     net_change_in_cash: int,
     *     opening_cash: int,
     *     closing_cash: int,
     *     reconciles_to_cash: bool,
     *     diagnostics: array{
     *         cfo_indirect: int,
     *         cfo_direct: int,
     *         cfo_matches: bool,
     *         fx_on_cash_indirect: int,
     *         total_debit: int,
     *         total_credit: int,
     *         debits_equal_credits: bool,
     *         unclassified_count: int,
     *         unclassified_lines: list<array{account_id:int, code:string, name:string, amount:int}>,
     *     },
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()->get()->keyBy('id');

        $cashAccountIds = $accounts
            ->filter(fn (Account $a) => $a->cf_class === CashFlowClass::Cash)
            ->pluck('id')
            ->all();

        $openingCash = $this->sumDebitNormalNet($this->ledger->cumulativeAsOf($from->subDay(), $cashAccountIds));
        $closingCash = $this->sumDebitNormalNet($this->ledger->cumulativeAsOf($to, $cashAccountIds));

        $direct = $this->classifyCashLedger($from, $to, $accounts);
        $indirect = $this->buildIndirectOperating($from, $to, $accounts);

        $cfoIndirect = $indirect['total'];
        $cfoDirect = $direct['buckets']['operating'];
        $cfi = $direct['buckets']['investing'];
        $cff = $direct['buckets']['financing'];
        $fxOnCash = $direct['buckets']['fx'];

        $netChangeInCash = $cfoIndirect + $cfi + $cff + $fxOnCash;

        $periodMovement = $this->ledger->movement($from, $to);
        $totalDebit = array_sum(array_column($periodMovement, 'debit'));
        $totalCredit = array_sum(array_column($periodMovement, 'credit'));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'operating' => [
                'net_income' => $indirect['net_income'],
                'add_backs' => $indirect['add_backs'],
                'fx_reclass' => $indirect['fx_reclass'],
                'working_capital' => $indirect['working_capital'],
                'total' => $cfoIndirect,
            ],
            'investing' => $direct['sections']['investing'],
            'financing' => $direct['sections']['financing'],
            'fx_on_cash' => $fxOnCash,
            'net_change_in_cash' => $netChangeInCash,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'reconciles_to_cash' => ($openingCash + $netChangeInCash) === $closingCash,
            'diagnostics' => [
                'cfo_indirect' => $cfoIndirect,
                'cfo_direct' => $cfoDirect,
                'cfo_matches' => $cfoIndirect === $cfoDirect,
                'fx_on_cash_indirect' => $indirect['fx_contribution'],
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'debits_equal_credits' => $totalDebit === $totalCredit,
                'unclassified_count' => count($direct['unclassified']),
                'unclassified_lines' => $direct['unclassified'],
            ],
        ];
    }

    /**
     * ENGINE A. Every journal_line in [from, to], grouped by its own
     * journal_entry, split into cash-pool lines vs. everything else. Only
     * entries that touch AT LEAST ONE cash-pool line matter here — an entry
     * with zero cash-pool lines moved no cash, full stop (e.g. a
     * depreciation JE), so it is skipped without inspecting its other lines
     * at all.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{
     *     buckets: array{operating:int, investing:int, financing:int, fx:int},
     *     sections: array{investing: array{rows:list<array{account_id:int,code:string,name:string,amount:int}>, total:int}, financing: array{rows:list<array{account_id:int,code:string,name:string,amount:int}>, total:int}},
     *     unclassified: list<array{account_id:int, code:string, name:string, amount:int}>,
     * }
     */
    private function classifyCashLedger(CarbonImmutable $from, CarbonImmutable $to, Collection $accounts): array
    {
        $lines = JournalLine::query()
            ->whereHas('journalEntry', fn ($e) => $e->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->get();

        $buckets = ['operating' => 0, 'investing' => 0, 'financing' => 0, 'fx' => 0];
        /** @var array<string, array<int, array{account_id:int, code:string, name:string, amount:int}>> $accountRows */
        $accountRows = ['investing' => [], 'financing' => []];
        $unclassified = [];

        foreach ($lines->groupBy('journal_entry_id') as $entryLines) {
            $cashLineIds = [];
            foreach ($entryLines as $line) {
                if (($accounts->get($line->account_id))?->cf_class === CashFlowClass::Cash) {
                    $cashLineIds[$line->id] = true;
                }
            }

            if ($cashLineIds === []) {
                // No cash-pool line in this entry at all -> no cash moved,
                // e.g. a depreciation JE. Nothing to classify.
                continue;
            }

            foreach ($entryLines as $line) {
                if (isset($cashLineIds[$line->id])) {
                    continue;
                }

                $contribution = $line->credit - $line->debit;
                if ($contribution === 0) {
                    continue;
                }

                $account = $accounts->get($line->account_id);
                $bucket = $this->bucketFor($account);

                if ($bucket === null) {
                    // $account is virtually always non-null in practice
                    // (journal_lines.account_id is a restrictOnDelete FK
                    // into THIS company's own accounts, all of which are
                    // preloaded into $accounts, including archived ones) —
                    // guarded defensively rather than crashing, since an
                    // unclassifiable line must never be silently dropped
                    // either way.
                    $unclassified[] = [
                        'account_id' => $account?->id ?? $line->account_id,
                        'code' => $account?->code ?? '?',
                        'name' => $account?->name ?? 'Unknown account',
                        'amount' => $contribution,
                    ];

                    continue;
                }

                $buckets[$bucket] += $contribution;

                if ($bucket === 'investing' || $bucket === 'financing') {
                    $accountRows[$bucket][$account->id] ??= [
                        'account_id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'amount' => 0,
                    ];
                    $accountRows[$bucket][$account->id]['amount'] += $contribution;
                }
            }
        }

        $sections = [];
        foreach (['investing', 'financing'] as $key) {
            $rows = array_values(array_filter($accountRows[$key], fn (array $r) => $r['amount'] !== 0));
            usort($rows, fn (array $a, array $b) => $a['code'] <=> $b['code']);
            $sections[$key] = ['rows' => $rows, 'total' => $buckets[$key]];
        }

        return ['buckets' => $buckets, 'sections' => $sections, 'unclassified' => $unclassified];
    }

    /**
     * Which bucket a NON-CASH line's account belongs to, for Engine A —
     * null means "cannot classify" (surfaced as a loud finding, assertion 5
     * — never silently dropped).
     */
    private function bucketFor(?Account $account): ?string
    {
        if ($account === null) {
            return null;
        }

        if ($account->code === self::RETAINED_EARNINGS_CODE) {
            // A direct-to-3200 posting is never NI (3200 is Equity, not
            // Income/Expense) — treat as a dividend/prior-period-adjustment
            // style Financing flow, mirroring "dividends in Financing".
            return 'financing';
        }

        if (in_array($account->code, self::FX_CODES, true)) {
            return 'fx';
        }

        if ($account->type === AccountType::Income || $account->type === AccountType::Expense) {
            // Ordinary P&L defaults to Operating; an account explicitly
            // reclassified Investing/Financing (e.g. a depreciation expense,
            // or a gain/loss on disposal) follows that override instead.
            return match ($account->cf_class) {
                CashFlowClass::Investing => 'investing',
                CashFlowClass::Financing => 'financing',
                default => 'operating',
            };
        }

        return match ($account->cf_class) {
            CashFlowClass::Operating => 'operating',
            CashFlowClass::Investing => 'investing',
            CashFlowClass::Financing => 'financing',
            default => null, // null, Excluded, or (unexpectedly) Cash -> unclassified.
        };
    }

    /**
     * ENGINE B. Net income identical to ProfitAndLossReport's own
     * (same LedgerBalances::movement() call over Income+Expense — the two
     * can never disagree), then the add-backs/FX-reclass/working-capital
     * adjustments described in this class's own docblock.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{
     *     net_income: int,
     *     add_backs: array{rows: list<array{account_id:int,code:string,name:string,amount:int}>, total:int},
     *     fx_reclass: int,
     *     fx_contribution: int,
     *     working_capital: array{rows: list<array{account_id:int,code:string,name:string,amount:int}>, total:int},
     *     total: int,
     * }
     */
    private function buildIndirectOperating(CarbonImmutable $from, CarbonImmutable $to, Collection $accounts): array
    {
        $plMovement = $this->ledger->movement($from, $to, null, [AccountType::Income, AccountType::Expense]);

        $netIncome = 0;
        foreach ($plMovement as $row) {
            $netIncome += -$row['net'];
        }

        // Non-cash reclass: any Income/Expense account explicitly tagged
        // Investing or Financing (the depreciation-expense rule + its
        // symmetric income-side case, e.g. a gain on disposal) is pulled out
        // of Operating by exactly its own NI contribution. FX (4950/5950) is
        // handled separately below — those two are 'excluded', never
        // Investing/Financing, so they can never double-count here.
        $addBackRows = [];
        $addBackTotal = 0;
        foreach ($accounts as $account) {
            if ($account->type !== AccountType::Income && $account->type !== AccountType::Expense) {
                continue;
            }
            if ($account->cf_class !== CashFlowClass::Investing && $account->cf_class !== CashFlowClass::Financing) {
                continue;
            }

            $net = $plMovement[$account->id]['net'] ?? 0;
            if ($net === 0) {
                continue;
            }

            // Reclass amount = -contribution = -(-net) = net: an expense's
            // net is already "positive is normal" (a genuine add-back); an
            // income account's net (negative when credited normally) yields
            // a NEGATIVE row here, correctly removing a gain from Operating
            // rather than adding it.
            $addBackRows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $net,
            ];
            $addBackTotal += $net;
        }
        usort($addBackRows, fn (array $a, array $b) => $a['code'] <=> $b['code']);

        // FX reclass: pull 4950/5950's combined NI contribution out of
        // Operating (fx_reclass, negative) and surface it as its own
        // "Effect of FX on cash" figure (fx_contribution, positive when a
        // net gain) — see class docblock's POLICY DEFAULT note.
        $fxContribution = 0;
        foreach (self::FX_CODES as $code) {
            $account = $accounts->first(fn (Account $a) => $a->code === $code);
            if ($account === null) {
                continue;
            }
            $net = $plMovement[$account->id]['net'] ?? 0;
            $fxContribution += -$net;
        }
        $fxReclass = -$fxContribution;

        // Working capital: every Asset/Liability/Equity account tagged
        // Operating (AR, prepaid, AP, accrued, deferred revenue, tax
        // payable, ...). cash_contrib = -movement, same formula Engine A
        // uses per line (credit - debit), applied to the account's
        // whole-period movement instead.
        $wcAccountIds = $accounts
            ->filter(fn (Account $a) => $a->cf_class === CashFlowClass::Operating
                && in_array($a->type, [AccountType::Asset, AccountType::Liability, AccountType::Equity], true))
            ->pluck('id')
            ->all();
        $wcMovement = $this->ledger->movement($from, $to, $wcAccountIds);

        $wcRows = [];
        $wcTotal = 0;
        foreach ($accounts as $account) {
            if (! in_array($account->id, $wcAccountIds, true)) {
                continue;
            }
            $net = $wcMovement[$account->id]['net'] ?? 0;
            if ($net === 0) {
                continue;
            }
            $contribution = -$net;
            $wcRows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $contribution,
            ];
            $wcTotal += $contribution;
        }
        usort($wcRows, fn (array $a, array $b) => $a['code'] <=> $b['code']);

        $total = $netIncome + $addBackTotal + $fxReclass + $wcTotal;

        return [
            'net_income' => $netIncome,
            'add_backs' => ['rows' => $addBackRows, 'total' => $addBackTotal],
            'fx_reclass' => $fxReclass,
            'fx_contribution' => $fxContribution,
            'working_capital' => ['rows' => $wcRows, 'total' => $wcTotal],
            'total' => $total,
        ];
    }

    /**
     * @param  array<int, array{account_id:int, debit:int, credit:int, net:int}>  $balances
     */
    private function sumDebitNormalNet(array $balances): int
    {
        $sum = 0;
        foreach ($balances as $row) {
            $sum += $row['net'];
        }

        return $sum;
    }
}
