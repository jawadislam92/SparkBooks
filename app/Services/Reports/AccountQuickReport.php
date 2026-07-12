<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Account QuickReport (Phase 7 Task 5): one account's full transaction list
 * over a [from, to] period — the drill-down TARGET every figure in P&L /
 * Balance Sheet / Trial Balance links into (?account_id=&from=&to=). Reads
 * exclusively through LedgerBalances for the opening/closing figures
 * (PROJECT_MAP §9 rule 9 — reports derive from journal_lines, never
 * document totals) and a direct journal_lines query for the line-level
 * detail, mirroring GeneralLedgerReport's own split. This class performs NO
 * ledger writes.
 *
 * SIGN CONVENTION: identical to GeneralLedgerReport — the opening/running/
 * closing balance is presented in the account's own natural side (debit-
 * normal accounts show the raw net; credit-normal accounts show the
 * flipped net). Per-line debit/credit columns are always the raw ledger
 * amounts (never flipped); only the running total that accumulates them is
 * sign-adjusted.
 *
 * SUB-ACCOUNT ROLLUP — the one place this diverges from
 * GeneralLedgerReport: GL deliberately shows a parent/header account with
 * an EMPTY line list (only its rolled-up opening/closing balance), which is
 * correct for a "list every account" screen where each descendant already
 * gets its own section elsewhere in the same payload. AccountQuickReport is
 * a single-account drill-down with nowhere else for a parent's activity to
 * show, so when the requested account is a PARENT (has descendants) this
 * class instead AGGREGATES every descendant LEAF's lines into one combined,
 * date-then-id-ordered list with a rolled-up running balance — exactly what
 * an accountant expects after clicking a subtotal figure. A LEAF account
 * behaves exactly like GeneralLedgerReport's own per-account view: only its
 * own lines.
 *
 * `Account::subtreeAccountIds()` (self + every descendant id, at any depth)
 * is the one query scope used throughout — for a leaf it degenerates to
 * `[account.id]` (no descendants), so the leaf and parent code paths are
 * unified rather than branched. Each descendant may in principle carry a
 * different (though in practice always matching) AccountType than the
 * requested parent, so the sign flip is looked up PER underlying account,
 * never assumed from the top-level account's own type — the same
 * per-account-then-fold approach GeneralLedgerReport/AccountRollup use for
 * their own rollups.
 */
class AccountQuickReport
{
    public function __construct(private LedgerBalances $ledger) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     type: string,
     *     is_leaf: bool,
     *     opening_balance: int,
     *     lines: list<array{
     *         id: int,
     *         account_id: int,
     *         account_code: string,
     *         account_name: string,
     *         entry_id: int,
     *         entry_number: ?int,
     *         entry_number_display: ?string,
     *         reverses_entry_number: ?int,
     *         reverses_entry_number_display: ?string,
     *         entry_date: string,
     *         entry_role: string,
     *         source_type: string,
     *         source_id: int,
     *         source_label: string,
     *         memo: ?string,
     *         contact_id: ?int,
     *         contact_name: ?string,
     *         debit: int,
     *         credit: int,
     *         running_balance: int,
     *     }>,
     *     closing_balance: int,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, int $accountId): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        // findOrFail — BelongsToCompany's global scope makes a foreign
        // company's account id 404 (ModelNotFoundException) exactly like
        // every other tenant-scoped lookup in this app (PROJECT_MAP §9
        // rule 7).
        $account = Account::query()->findOrFail($accountId);

        // Self + every descendant at any depth; degenerates to [account.id]
        // for a leaf (no descendants) — see class docblock.
        $accountIds = $account->subtreeAccountIds();
        $subtreeAccounts = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $openingNet = $this->ledger->cumulativeAsOf($from->subDay(), $accountIds);
        $opening = 0;
        foreach ($accountIds as $id) {
            $raw = $openingNet[$id]['net'] ?? 0;
            $opening += $subtreeAccounts[$id]->type->isDebitNormal() ? $raw : -$raw;
        }

        $lines = $this->fetchLines($from, $to, $accountIds);
        $contactNames = $this->contactNames($lines);

        $running = $opening;
        $lineRows = [];
        foreach ($lines as $line) {
            $lineAccount = $subtreeAccounts[$line->account_id];
            $isDebitNormal = $lineAccount->type->isDebitNormal();
            $running += $isDebitNormal ? ($line->debit - $line->credit) : ($line->credit - $line->debit);
            $entry = $line->journalEntry;

            $lineRows[] = [
                'id' => $line->id,
                // Which sub-account this line actually belongs to — only
                // meaningfully varies from the requested account when it's
                // a rolled-up parent view; for a leaf every line repeats
                // the same account, which is harmless.
                'account_id' => $line->account_id,
                'account_code' => $lineAccount->code,
                'account_name' => $lineAccount->name,
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

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'account_id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'is_leaf' => $account->isPostable(),
            'opening_balance' => $opening,
            'lines' => $lineRows,
            'closing_balance' => $running,
        ];
    }

    /**
     * Every journal_line whose entry falls in [from, to] AND whose
     * account_id is in the subtree, eager-loading the parent entry and
     * sorted chronologically then by the line's own id (same tie-break
     * GeneralLedgerReport uses) — for a parent this interleaves every
     * descendant's lines into one combined chronological list, which is
     * exactly the "aggregate + order by date, entry" sub-account rule.
     *
     * @param  list<int>  $accountIds
     * @return Collection<int, JournalLine>
     */
    private function fetchLines(CarbonImmutable $from, CarbonImmutable $to, array $accountIds): Collection
    {
        return JournalLine::query()
            ->with('journalEntry.reversesEntry')
            ->whereIn('account_id', $accountIds)
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
