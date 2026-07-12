<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * Balance Sheet (spec §7.11.2): Assets / Liabilities / Equity at a single
 * as-of date. Reads exclusively through LedgerBalances (PROJECT_MAP §9 rule
 * 9 — reports derive from journal_lines, never document totals), so this
 * class performs NO ledger writes and is safe to call from a read-only
 * request cycle. Company scoping is implicit via Account/JournalLine's
 * BelongsToCompany global scope — this class never accepts or passes
 * company_id, mirroring TrialBalanceReport/ProfitAndLossReport.
 *
 * SIGN CONVENTION: identical to TrialBalanceReport/ProfitAndLossReport —
 * every amount is presented "positive is normal for that side". Assets are
 * debit-normal (net as-is). Liabilities and Equity are credit-normal (net
 * flipped, i.e. -net). No debit/credit language anywhere in this class or
 * its output (PROJECT_MAP §9 rule 8 — the Balance Sheet is a non-accountant
 * screen).
 *
 * THE RETAINED EARNINGS RULE (§7.11.2 — the hardest tie-out in Phase 5):
 *   Retained Earnings line = posted balance of account 3200
 *                             + net income of every COMPLETED fiscal year
 *                               (income - expense movement, via
 *                               LedgerBalances::movement, for each FY
 *                               strictly before the one containing $asOf).
 *   Current-Year Earnings  = net income of the CURRENT (incomplete) fiscal
 *                             year, from its start through $asOf, shown as
 *                             its OWN separate equity line — never folded
 *                             into Retained Earnings.
 *   Account 3200's raw posted balance is NEVER also listed as its own
 *   equity row — the computed Retained Earnings line REPLACES it, so
 *   nothing is double-counted.
 *
 * WHY THIS ALWAYS BALANCES: the ledger's own double-entry invariant means
 * that, cumulative through any date, Σ(net) across EVERY account of EVERY
 * type is exactly zero. That lets Total Assets be restated as
 * -(ΣLiability net) - (ΣEquity net, all of it, incl. 3200) - (ΣIncome net)
 * - (ΣExpense net). The first term is Total Liabilities; the second splits
 * into "raw equity excl. 3200" + "3200's own posted balance" (both flipped
 * to credit-normal display); the last two terms together are exactly
 * cumulative net income since inception, restated in "positive income,
 * positive expense" convention. As long as the completed-FY windows plus
 * the current-FY window partition that entire cumulative span with no gap
 * or overlap, Σ(completed-FY net income) + current-year earnings equals
 * that same cumulative net income — which is exactly what
 * ReportPeriod::completedFiscalYears()/currentFiscalYearStart() guarantee
 * (see resolveSinceDate()'s docblock for why an unaligned books_start_date,
 * or a missing one, still can't break this).
 */
class BalanceSheetReport
{
    public function __construct(private LedgerBalances $ledger, private CompanyContext $context, private AccountRollup $rollup) {}

    /**
     * @return array{
     *     as_of: string,
     *     assets: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *     liabilities: array{rows: list<array{account_id:int, code:string, name:string, amount:int}>, total:int},
     *     equity: array{
     *         rows: list<array{account_id:int, code:string, name:string, amount:int}>,
     *         retained_earnings: int,
     *         current_year_earnings: int,
     *         total: int,
     *     },
     *     total_assets: int,
     *     total_liabilities_and_equity: int,
     *     is_balanced: bool,
     *     difference: int,
     *     retained_earnings_detail: array{
     *         posted_balance: int,
     *         completed_fiscal_years_net_income: int,
     *         completed_fiscal_years: list<array{start:string, end:string, net_income:int}>,
     *     },
     *     current_fiscal_year: array{start: string, end: string},
     * }
     */
    public function build(CarbonImmutable $asOf): array
    {
        $asOf = $asOf->startOfDay();
        $company = $this->context->get();

        $retainedEarningsAccount = Account::query()->where('code', '3200')->first();

        $assets = $this->accountSection(AccountType::Asset, $asOf, isDebitNormal: true);
        $liabilities = $this->accountSection(AccountType::Liability, $asOf, isDebitNormal: false);
        $equityRows = $this->accountSection(
            AccountType::Equity,
            $asOf,
            isDebitNormal: false,
            excludeAccountId: $retainedEarningsAccount?->id,
        );

        $postedRetainedEarnings = 0;
        if ($retainedEarningsAccount !== null) {
            $balances = $this->ledger->cumulativeAsOf($asOf, [$retainedEarningsAccount->id]);
            $net = $balances[$retainedEarningsAccount->id]['net'] ?? 0;
            $postedRetainedEarnings = -$net; // credit-normal flip, same convention as every other equity row.
        }

        $period = new ReportPeriod($company->fiscal_year_start_month);
        $since = $this->resolveSinceDate($company, $asOf);

        $completedFiscalYears = [];
        $completedNetIncome = 0;
        foreach ($period->completedFiscalYears($asOf, $since) as $fy) {
            $netIncome = $this->netIncome($fy['start'], $fy['end']);
            $completedNetIncome += $netIncome;
            $completedFiscalYears[] = [
                'start' => $fy['start']->toDateString(),
                'end' => $fy['end']->toDateString(),
                'net_income' => $netIncome,
            ];
        }

        $currentFiscalYearStart = $period->currentFiscalYearStart($asOf);
        $currentYearEarnings = $this->netIncome($currentFiscalYearStart, $asOf);

        $retainedEarnings = $postedRetainedEarnings + $completedNetIncome;
        $equityTotal = $equityRows['total'] + $retainedEarnings + $currentYearEarnings;
        $totalLiabilitiesAndEquity = $liabilities['total'] + $equityTotal;

        return [
            'as_of' => $asOf->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => [
                'rows' => $equityRows['rows'],
                'retained_earnings' => $retainedEarnings,
                'current_year_earnings' => $currentYearEarnings,
                'total' => $equityTotal,
            ],
            'total_assets' => $assets['total'],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => $assets['total'] === $totalLiabilitiesAndEquity,
            'difference' => $assets['total'] - $totalLiabilitiesAndEquity,
            'retained_earnings_detail' => [
                'posted_balance' => $postedRetainedEarnings,
                'completed_fiscal_years_net_income' => $completedNetIncome,
                'completed_fiscal_years' => $completedFiscalYears,
            ],
            'current_fiscal_year' => [
                'start' => $currentFiscalYearStart->toDateString(),
                'end' => $asOf->toDateString(),
            ],
        ];
    }

    /**
     * One query for the type's cumulative balances, joined in PHP against
     * every account of that type, depth-first ordered (see AccountRollup) —
     * mirrors TrialBalanceReport::build(). Zero-net accounts are skipped
     * entirely (never-used AND fully-offset alike, LEAF rows only — same
     * skip rule as TrialBalanceReport/ProfitAndLossReport); an empty parent
     * header is skipped the same way once its rollup is zero.
     *
     * Phase 7 Task 1: parent (header) rows are a pure rollup of their
     * descendant leaves and never contribute to `$total` a second time (a
     * leaf's amount is already counted once) — a company with no
     * sub-accounts degenerates to the exact same flat list and total as
     * before.
     *
     * @return array{rows: list<array{account_id:int, code:string, name:string, amount:int, depth:int, is_subtotal:bool}>, total:int}
     */
    private function accountSection(AccountType $type, CarbonImmutable $asOf, bool $isDebitNormal, ?int $excludeAccountId = null): array
    {
        $balances = $this->ledger->cumulativeAsOf($asOf, null, [$type]);

        $accounts = Account::query()->where('type', $type->value)->get()
            ->reject(fn (Account $account) => $account->id === $excludeAccountId)
            ->values();
        $ordered = $this->rollup->order($accounts);

        $rawAmounts = [];
        foreach ($accounts as $account) {
            $net = $balances[$account->id]['net'] ?? 0;
            $rawAmounts[$account->id] = $isDebitNormal ? $net : -$net;
        }
        $rolledAmounts = $this->rollup->rollup($ordered, $rawAmounts);

        $rows = [];
        $total = 0;

        foreach ($ordered as $account) {
            $amount = $account->isPostable() ? $rawAmounts[$account->id] : ($rolledAmounts[$account->id] ?? 0);

            if ($amount === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount,
                'depth' => $account->depth,
                'is_subtotal' => ! $account->isPostable(),
            ];

            if ($account->isPostable()) {
                $total += $amount;
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Net income (positive-is-profit convention) for [$from, $to], summed
     * across every Income and Expense account — the exact same sign flip
     * ProfitAndLossReport::section() applies (income is credit-normal so its
     * net is flipped; expense is debit-normal so it's used as-is), computed
     * independently here (rather than delegating to ProfitAndLossReport)
     * because this report only ever needs the two totals, not per-account
     * rows or monthly buckets — both paths bottom out in the same
     * LedgerBalances::movement() call, so they can never diverge.
     */
    private function netIncome(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $incomeMovement = $this->ledger->movement($from, $to, null, [AccountType::Income]);
        $expenseMovement = $this->ledger->movement($from, $to, null, [AccountType::Expense]);

        $incomeTotal = 0;
        foreach ($incomeMovement as $row) {
            $incomeTotal += -$row['net'];
        }

        $expenseTotal = 0;
        foreach ($expenseMovement as $row) {
            $expenseTotal += $row['net'];
        }

        return $incomeTotal - $expenseTotal;
    }

    /**
     * The lower bound ReportPeriod::completedFiscalYears() needs (its own
     * docblock: "there is no sensible unbounded default"). Prefers
     * company.books_start_date (spec's intended source). Falls back to the
     * the EARLIER of company.books_start_date and the company's earliest
     * posted entry_date. books_start_date ALONE is not a safe lower bound:
     * a company can post real activity dated BEFORE books_start_date — e.g.
     * a prior-period expense entered after the fact, or (on accumulated dev
     * data) any entry that predates when the books were declared to start.
     * Anchoring the completed-FY rollup at books_start_date would drop that
     * activity's net income entirely — it falls in NO completed-FY window
     * (all of which start at or after books_start_date) AND before the
     * current FY — so its income/expense net vanishes from Retained
     * Earnings, overstating equity and breaking the §7.11.2 MUST-BALANCE
     * identity (Assets == Liabilities + Equity). Taking the min with the
     * earliest posted entry_date guarantees EVERY completed FY that holds
     * any activity is enumerated, so nothing pre-current-FY is ever
     * dropped, whichever of the two bounds is earlier.
     *
     * Falls back to $asOf only when there is truly no ledger activity at
     * all AND no books_start_date (an empty company) — the choice is moot
     * there: zero completed years, zero net income either way.
     *
     * Safe even when the chosen bound lands BEFORE the true start of
     * activity (e.g. books_start_date not aligned to the fiscal-year
     * boundary, so the first "completed" FY window technically starts
     * earlier still): the extra leading days contribute zero net income
     * because nothing is posted before the earliest entry, so the rollup is
     * unaffected either way.
     */
    private function resolveSinceDate(Company $company, CarbonImmutable $asOf): CarbonImmutable
    {
        $candidates = [];

        if ($company->books_start_date !== null) {
            $candidates[] = CarbonImmutable::parse($company->books_start_date->toDateString());
        }

        $earliest = JournalEntry::query()->min('entry_date');
        if ($earliest !== null) {
            $candidates[] = CarbonImmutable::parse($earliest);
        }

        if ($candidates === []) {
            return $asOf;
        }

        $since = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate->lt($since)) {
                $since = $candidate;
            }
        }

        return $since;
    }
}
