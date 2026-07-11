<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * General Ledger (spec §7.11 item 5): per-account line detail over a
 * [from, to] period — an opening balance, every journal line posted in the
 * period, a running balance, and a closing balance. This is the Gate-B GL
 * artifact and the drill-down TARGET every other report (P&L, Balance
 * Sheet, agings) links into. Reads exclusively through LedgerBalances for
 * the opening/closing figures (PROJECT_MAP §9 rule 9 — reports derive from
 * journal_lines, never document totals) and a direct journal_lines query for
 * the line-level detail (LedgerBalances is intentionally account-level only
 * — see AgingReport's class docblock for why a per-line/per-document view
 * can't be built on top of it). This class performs NO ledger writes.
 *
 * SIGN CONVENTION (GL is an accountant screen — PROJECT_MAP §9 rule 8 does
 * NOT apply here, debit/credit columns are shown as-is): the opening,
 * running, and closing BALANCE figures are presented in the account's own
 * NATURAL side, identical to the existing AccountController::show
 * drill-down ("THE MIRROR") and to the "positive is normal" convention
 * every other Phase 5 report uses (TrialBalanceReport/BalanceSheetReport/
 * ProfitAndLossReport): debit-normal accounts (Asset, Expense) show the raw
 * net (debit − credit); credit-normal accounts (Liability, Equity, Income)
 * show the flipped net (credit − debit). The per-LINE debit/credit columns
 * are always the raw ledger amounts (never flipped) — only the running
 * total that accumulates them is sign-adjusted.
 *
 * THE INTERNAL-CONSISTENCY TIE (load-bearing, see GeneralLedgerReportTest):
 * opening_balance + Σ(each listed line's signed movement) == closing_balance
 * == (isDebitNormal ? 1 : -1) * LedgerBalances::cumulativeAsOf($to)[account]['net'].
 * This holds by construction whenever every line in [from, to] for that
 * account is included in the listing (i.e. $contactId is null): opening is
 * cumulativeAsOf($from - 1 day)'s net (sign-adjusted), and
 * cumulativeAsOf($from - 1 day)['net'] + movement($from, $to)['net'] ==
 * cumulativeAsOf($to)['net'] because the two windows (entry_date <= from-1,
 * and from <= entry_date <= to) partition "entry_date <= to" with no gap or
 * overlap — the same reasoning ProfitAndLossReport's monthly split and
 * BalanceSheetReport's fiscal-year rollup both rely on.
 *
 * THE CONTACT FILTER (optional drill-down convenience, e.g. an aging
 * report's "this customer's GL activity" link): narrows the LISTED lines to
 * one contact_id, but deliberately does NOT attempt a contact-level
 * sub-ledger — opening_balance is still the WHOLE account's balance (a
 * per-contact opening balance would need AgingReport's much heavier
 * per-document reconstruction, not justified for a "your call" convenience
 * filter). So closing_balance (folded from opening through only the
 * filtered lines) will NOT generally tie to cumulativeAsOf($to) once a
 * contact filter is active — that tie is only guaranteed when $contactId
 * is null.
 */
class GeneralLedgerReport
{
    public function __construct(private LedgerBalances $ledger, private AccountRollup $rollup) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     account_id: ?int,
     *     contact_id: ?int,
     *     accounts: list<array{
     *         account_id: int,
     *         code: string,
     *         name: string,
     *         type: string,
     *         opening_balance: int,
     *         lines: list<array{
     *             id: int,
     *             entry_id: int,
     *             entry_number: ?int,
     *             entry_number_display: ?string,
     *             reverses_entry_number: ?int,
     *             reverses_entry_number_display: ?string,
     *             entry_date: string,
     *             entry_role: string,
     *             source_type: string,
     *             source_id: int,
     *             source_label: string,
     *             memo: ?string,
     *             contact_id: ?int,
     *             contact_name: ?string,
     *             debit: int,
     *             credit: int,
     *             running_balance: int,
     *         }>,
     *         closing_balance: int,
     *     }>,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId = null, ?int $contactId = null): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        // Rollup machinery always runs over EVERY company account, never
        // filtered by $accountId — a directly-selected PARENT's subtotal
        // still needs its whole subtree's figures even though only that one
        // account ends up in the OUTPUT list below.
        $allAccounts = Account::query()->get();
        $ordered = $this->rollup->order($allAccounts);

        $candidateAccounts = $accountId !== null
            ? $allAccounts->filter(fn (Account $account) => $account->id === $accountId)->values()->all()
            : $ordered;

        // One aggregated query for every account's opening balance — mirrors
        // TrialBalanceReport/BalanceSheetReport's "one query, join in PHP"
        // shape rather than querying per-account in a loop.
        $openingNet = $this->ledger->cumulativeAsOf($from->subDay());

        // PARENT (header) rollup rows never carry lines of their own
        // (leaf-only posting, enforced by LedgerService/IntegrityChecker),
        // so their closing_balance can't be folded through a line list the
        // way a leaf's is — it is instead Σ their descendant leaves' whole-
        // ledger closing net, from a second cumulativeAsOf($to) query. This
        // mirrors opening_balance's own existing convention (every row's
        // opening_balance is already whole-account and contact-filter-
        // agnostic, regardless of $contactId) — a PARENT's closing_balance
        // is contact-filter-agnostic the same way; only a LEAF's own
        // (folded) closing_balance below still respects $contactId.
        $closingNetForRollup = $this->ledger->cumulativeAsOf($to);

        $rawOpening = [];
        $rawClosingForRollup = [];
        foreach ($allAccounts as $account) {
            $isDebitNormal = $account->type->isDebitNormal();
            $openingRaw = $openingNet[$account->id]['net'] ?? 0;
            $closingRaw = $closingNetForRollup[$account->id]['net'] ?? 0;
            $rawOpening[$account->id] = $isDebitNormal ? $openingRaw : -$openingRaw;
            $rawClosingForRollup[$account->id] = $isDebitNormal ? $closingRaw : -$closingRaw;
        }
        $rolledOpening = $this->rollup->rollup($ordered, $rawOpening);
        $rolledClosing = $this->rollup->rollup($ordered, $rawClosingForRollup);

        $lines = $this->fetchLines($from, $to, $accountId, $contactId);
        $contactNames = $this->contactNames($lines);
        $linesByAccount = $lines->groupBy('account_id');

        $accountsOut = [];
        foreach ($candidateAccounts as $account) {
            $isLeaf = $account->isPostable();

            if ($isLeaf) {
                $accountLines = $linesByAccount->get($account->id) ?? collect();
                $openingRaw = $openingNet[$account->id]['net'] ?? 0;

                // In "all accounts" mode, skip an account that's never been
                // touched at all (mirrors TrialBalanceReport's skip rule). A
                // directly-selected account ($accountId !== null) is ALWAYS
                // included, even with zero balance and zero lines -- the
                // "empty account" case the brief calls out explicitly.
                if ($accountId === null && $openingRaw === 0 && $accountLines->isEmpty()) {
                    continue;
                }

                $isDebitNormal = $account->type->isDebitNormal();
                $opening = $isDebitNormal ? $openingRaw : -$openingRaw;

                $running = $opening;
                $lineRows = [];
                foreach ($accountLines as $line) {
                    $running += $isDebitNormal ? ($line->debit - $line->credit) : ($line->credit - $line->debit);
                    $entry = $line->journalEntry;

                    $lineRows[] = [
                        'id' => $line->id,
                        'entry_id' => $entry->id,
                        // Phase 7 Task 3: the human-readable, gap-free,
                        // per-company number — distinct from entry_id (the
                        // DB row id) above. reverses_entry_number is only
                        // ever non-null on a reversal line (entry_role ===
                        // 'reversal'), surfacing the ORIGINAL entry's own
                        // number alongside the reversal's — the reversal
                        // gets its own number, this just points back.
                        'entry_number' => $entry->entry_number,
                        'entry_number_display' => $entry->displayNumber(),
                        'reverses_entry_number' => $entry->reversesEntry?->entry_number,
                        'reverses_entry_number_display' => $entry->reversesEntry?->displayNumber(),
                        'entry_date' => $entry->entry_date->toDateString(),
                        'entry_role' => $entry->entry_role->value,
                        'source_type' => $entry->source_type,
                        'source_id' => $entry->source_id,
                        'source_label' => $this->sourceLabel($entry->source_type, $entry->source_id),
                        'memo' => $line->memo ?? $entry->memo,
                        'contact_id' => $line->contact_id,
                        'contact_name' => $line->contact_id !== null
                            ? ($contactNames->get($line->contact_id) ?? "Contact #{$line->contact_id}")
                            : null,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'running_balance' => $running,
                    ];
                }

                $closing = $running;
            } else {
                $opening = $rolledOpening[$account->id] ?? 0;
                $closing = $rolledClosing[$account->id] ?? 0;
                $lineRows = [];

                // Mirrors the leaf skip rule above: an untouched header (no
                // brought-forward balance, nothing posted under it through
                // $to) is skipped in "all accounts" mode.
                if ($accountId === null && $opening === 0 && $closing === 0) {
                    continue;
                }
            }

            $accountsOut[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'opening_balance' => $opening,
                'lines' => $lineRows,
                'closing_balance' => $closing,
                'depth' => $account->depth,
                'is_subtotal' => ! $isLeaf,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'accounts' => $accountsOut,
        ];
    }

    /**
     * Every journal_line whose entry falls in [from, to], eager-loading the
     * parent entry (needed for entry_date/source/memo/entry_role) and sorted
     * chronologically then by the line's own id — the exact ordering
     * AccountController::show's drill-down already uses ("THE MIRROR"), so a
     * single entry's several lines get a stable, deterministic order even
     * when they share an entry_date.
     *
     * @return Collection<int, JournalLine>
     */
    private function fetchLines(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId, ?int $contactId): Collection
    {
        $query = JournalLine::query()
            // journalEntry.reversesEntry is eager-loaded so a reversal
            // line's reverses_entry_number(_display) below costs no extra
            // per-line query.
            ->with('journalEntry.reversesEntry')
            ->whereHas('journalEntry', fn ($e) => $e->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]));

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        if ($contactId !== null) {
            $query->where('contact_id', $contactId);
        }

        return $query->get()
            ->sort(fn (JournalLine $a, JournalLine $b) => [$a->journalEntry->entry_date->toDateString(), $a->id]
                <=> [$b->journalEntry->entry_date->toDateString(), $b->id])
            ->values();
    }

    /**
     * @param  Collection<int, JournalLine>  $lines
     * @return Collection<int, string>
     */
    private function contactNames(Collection $lines): Collection
    {
        $contactIds = $lines->pluck('contact_id')->filter()->unique()->values();

        return $contactIds->isEmpty()
            ? collect()
            : Contact::query()->whereIn('id', $contactIds)->pluck('name', 'id');
    }

    /**
     * A simple, human-readable "what posted this" label — e.g. "Invoice #42",
     * "Manual journal #7", "Opening balance run #1". Deliberately does not
     * join out to the source document's own reference number (invoice
     * number, bill number, etc.) — that would mean a different join per
     * source_type, which isn't justified for a label whose job is just to
     * tell the reader WHAT KIND of thing posted this line and let them
     * follow source_type/source_id themselves if they need the document.
     */
    private function sourceLabel(string $sourceType, int $sourceId): string
    {
        $label = ucfirst(str_replace('_', ' ', $sourceType));

        return "{$label} #{$sourceId}";
    }
}
