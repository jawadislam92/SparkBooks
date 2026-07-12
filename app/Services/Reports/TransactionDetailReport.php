<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Transaction Detail by Account (Phase 7 Task 5): every posted journal_line
 * across a [from, to] period, grouped by account in chart-of-accounts order
 * (see AccountRollup::order), each with a running balance that starts at
 * ZERO at the top of every account's own section -- this is a PERIOD-
 * ACTIVITY report, not an open-to-close ledger view (that's
 * GeneralLedgerReport/AccountQuickReport, both of which fold in an opening
 * balance). Reads exclusively through a direct journal_lines query
 * (PROJECT_MAP §9 rule 9 — reports derive from journal_lines, never
 * document totals); this class performs NO ledger writes.
 *
 * THE TIE-OUT (load-bearing, see TransactionDetailReportTest): each
 * account's `net` (its own natural-side-signed running total after the
 * last listed line) MUST equal (isDebitNormal ? 1 : -1) *
 * LedgerBalances::movement(from, to, [account])[account]['net'] — the same
 * "positive is normal" sign convention GeneralLedgerReport/
 * TrialBalanceReport already use. Per-line debit/credit columns are always
 * the raw ledger amounts (never flipped); only the running total is
 * sign-adjusted.
 *
 * ONLY LEAF (postable) accounts are ever listed: a parent/header account
 * never carries a direct posting of its own (enforced by
 * LedgerService/IntegrityChecker), so it always has zero lines and is
 * skipped outright — "grouped by account" here means grouped by the
 * postable accounts that actually moved, not a parent/child rollup tree.
 * AccountQuickReport is the report that rolls a parent's descendants up
 * into one view; this one stays flat, one section per leaf account that had
 * activity. Company scoping is implicit via JournalLine/JournalEntry's
 * BelongsToCompany global scope — never pass or accept company_id here.
 */
class TransactionDetailReport
{
    public function __construct(private AccountRollup $rollup) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     accounts: list<array{
     *         account_id: int,
     *         code: string,
     *         name: string,
     *         type: string,
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
     *         subtotal_debit: int,
     *         subtotal_credit: int,
     *         net: int,
     *     }>,
     *     grand_total_debit: int,
     *     grand_total_credit: int,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        // Depth-first chart-of-accounts order (AccountRollup) — only ever
        // used for ORDERING here (never rollup()): parents are skipped
        // below since they never carry lines, so there is nothing to fold
        // up through them.
        $ordered = $this->rollup->order(Account::query()->get());

        $lines = $this->fetchLines($from, $to);
        $contactNames = $this->contactNames($lines);
        $linesByAccount = $lines->groupBy('account_id');

        $accountsOut = [];
        $grandDebit = 0;
        $grandCredit = 0;

        foreach ($ordered as $account) {
            if (! $account->isPostable()) {
                continue; // parents never carry direct postings -- see class docblock
            }

            $accountLines = $linesByAccount->get($account->id) ?? collect();
            if ($accountLines->isEmpty()) {
                continue;
            }

            $isDebitNormal = $account->type->isDebitNormal();
            $running = 0;
            $subtotalDebit = 0;
            $subtotalCredit = 0;
            $lineRows = [];

            foreach ($accountLines as $line) {
                $entry = $line->journalEntry;
                $running += $isDebitNormal ? ($line->debit - $line->credit) : ($line->credit - $line->debit);
                $subtotalDebit += $line->debit;
                $subtotalCredit += $line->credit;

                $lineRows[] = [
                    'id' => $line->id,
                    'entry_id' => $entry->id,
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

            $accountsOut[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'lines' => $lineRows,
                'subtotal_debit' => $subtotalDebit,
                'subtotal_credit' => $subtotalCredit,
                'net' => $running,
            ];

            $grandDebit += $subtotalDebit;
            $grandCredit += $subtotalCredit;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'accounts' => $accountsOut,
            'grand_total_debit' => $grandDebit,
            'grand_total_credit' => $grandCredit,
        ];
    }

    /**
     * Every journal_line whose entry falls in [from, to], eager-loading the
     * parent entry and sorted chronologically then by the line's own id —
     * identical shape to GeneralLedgerReport::fetchLines, just never
     * filtered to one account (this report always covers every account).
     *
     * @return Collection<int, JournalLine>
     */
    private function fetchLines(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return JournalLine::query()
            ->with('journalEntry.reversesEntry')
            ->whereHas('journalEntry', fn ($e) => $e->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->get()
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

    /** Mirrors GeneralLedgerReport::sourceLabel exactly — see that class for why it stays this simple. */
    private function sourceLabel(string $sourceType, int $sourceId): string
    {
        $label = ucfirst(str_replace('_', ' ', $sourceType));

        return "{$label} #{$sourceId}";
    }
}
