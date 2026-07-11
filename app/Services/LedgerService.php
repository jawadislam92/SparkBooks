<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Exceptions\Ledger\ArchivedAccount;
use App\Exceptions\Ledger\ControlAccountViolation;
use App\Exceptions\Ledger\EmptyEntry;
use App\Exceptions\Ledger\LockedPeriod;
use App\Exceptions\Ledger\NonPostableAccount;
use App\Exceptions\Ledger\UnbalancedEntry;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class LedgerService
{
    public function __construct(private CompanyContext $context, private DocumentNumberService $numbers) {}

    /**
     * THE one gate into the ledger. Callers must already hold a DB transaction
     * (documents + ledger commit together) — this method enforces that.
     */
    public function post(EntryDraft $draft): JournalEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('LedgerService::post must run inside the caller\'s transaction.');
        }
        $this->validate($draft);

        // Phase 7 Task 3 (visible entry numbers): allocated inside THIS
        // transaction, via the same FOR-UPDATE row-lock counter machinery
        // DocumentNumberService already uses for INV/BILL numbers (series
        // 'JE') — gap-free and duplicate-free under concurrent posts. A
        // reversal gets its OWN number here exactly like a primary entry;
        // the link back to the original entry it reverses is
        // reverses_entry_id (already set below), surfaced via
        // JournalEntry::reversesEntry()->displayNumber().
        $entryNumber = $this->numbers->nextNumber('JE');

        $entry = JournalEntry::query()->create([
            'entry_date' => $draft->entryDate->toDateString(),
            'memo' => $draft->memo,
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'entry_role' => $draft->role->value,
            'reverses_entry_id' => $draft->reversesEntryId,
            'created_by' => auth()->id(),
            'entry_number' => $entryNumber,
        ]);
        foreach ($draft->lines as $line) {
            // company_id is set by the BelongsToCompany creating hook from context
            $entry->lines()->create([
                'account_id' => $line->accountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'memo' => $line->memo,
                'contact_id' => $line->contactId,
            ]);
        }

        return $entry->load('lines');
    }

    /** Post a mirror-image reversal of an existing entry (same source, role=reversal). */
    public function reverse(JournalEntry $entry, CarbonImmutable $date, string $memo): JournalEntry
    {
        $lines = $entry->lines->map(fn (JournalLine $l) => new LineDraft(
            accountId: $l->account_id,
            debit: $l->credit,
            credit: $l->debit,
            memo: $l->memo,
            contactId: $l->contact_id,
        ))->all();

        return $this->post(new EntryDraft(
            entryDate: $date, memo: $memo,
            sourceType: $entry->source_type, sourceId: $entry->source_id,
            lines: $lines, role: EntryRole::Reversal, reversesEntryId: $entry->id,
        ));
    }

    private function validate(EntryDraft $draft): void
    {
        if ($draft->lines === []) {
            throw new EmptyEntry;
        }
        $debits = $credits = 0;
        foreach ($draft->lines as $line) {
            if ($line->debit < 0 || $line->credit < 0
                || ($line->debit === 0) === ($line->credit === 0)) { // exactly one side > 0
                throw new UnbalancedEntry('Each line must have exactly one positive side.');
            }
            $debits += $line->debit;
            $credits += $line->credit;
            $account = Account::query()->findOrFail($line->accountId); // tenant-scoped => foreign account 404s
            // Archived accounts are blocked only on NEW (primary) entries. Reversals must
            // always be postable — reversal is the ONLY correction path for immutable
            // entries, so an account archived AFTER an entry posts must not trap it forever.
            if ($account->is_archived && $draft->role === EntryRole::Primary) {
                throw new ArchivedAccount("Account {$account->code} is archived and cannot be used on a new entry.");
            }
            // Leaf-only posting, enforcement #1 of 3 (Phase 7 Task 1 — see
            // NonPostableAccount's docblock for #2/#3). Checked for BOTH
            // Primary and Reversal entries, unlike the archived-account guard
            // above: a reversal must always be POSTABLE to the exact same
            // account its original entry used, and an account can only ever
            // become non-postable via AccountService::makeParent(), which
            // itself moves that account's existing lines to a new leaf
            // first — so a reversal of a pre-promotion entry would already
            // be reversing lines that no longer sit on the now-parent
            // account, making this check unreachable for reversals in
            // practice, not a hole deliberately left ajar for them.
            if (! $account->isPostable()) {
                throw new NonPostableAccount("Account {$account->code} is not postable — it has sub-accounts and only leaf accounts may be posted to.");
            }
            if ($account->isControlAccount()) {
                if ($line->contactId === null) {
                    throw new ControlAccountViolation("Lines on {$account->code} require a contact.");
                }
                if ($draft->sourceType === 'manual_journal') {
                    throw new ControlAccountViolation('Manual journal entries may not touch AR/AP control accounts — void and reissue the source document instead.');
                }
            }
        }
        if ($debits !== $credits) {
            throw new UnbalancedEntry("Debits ({$debits}) must equal credits ({$credits}).");
        }
        $locked = $this->context->get()->books_locked_through;
        if ($locked && $draft->entryDate->lte(CarbonImmutable::parse($locked))) {
            throw new LockedPeriod("The books are locked through {$locked->format('Y-m-d')}.");
        }
    }
}
