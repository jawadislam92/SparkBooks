<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Exceptions\OpeningBalancesLocked;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\OpeningDocument;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
        private CompanyContext $context,
    ) {}

    /**
     * Applies (or re-applies) the guided opening-balances setup. Posting
     * rules P8a-c (spec §6), all landing in ONE ledger entry sourced from a
     * fresh `opening_balance_runs` row (spec §7.1):
     *   P8a — Dr each opening bank/cash balance.
     *   P8b — Dr 1100 AR per open customer invoice, WITH contact_id.
     *   P8c — Cr 2000 AP per open vendor bill, WITH contact_id.
     *   plug — exactly one 3100 Opening Balance Equity line balancing the rest.
     * Every AR/AP line carries contact_id (spec §6 note) so aging (Phase 5)
     * ties out to the control accounts.
     *
     * Re-running (an 'active' run already exists) VOIDS the prior run FIRST:
     * reverses its ledger entry, marks the run 'voided' and its opening
     * documents 'void' — UNLESS a real (non-opening) transaction has already
     * posted, in which case the whole call is refused (OpeningBalancesLocked)
     * before anything is touched. Each run is a fresh source row, so the
     * §17.2 unique index (company_id, source_type, source_id, entry_role) is
     * never at risk — nothing is ever edited in place or duplicated on top.
     *
     * Concurrency: this whole method runs inside ONE DB transaction (opened
     * by IdempotencyService::execute), and the very first thing it does is
     * take a `lockForUpdate()` on the current company's row. That serializes
     * concurrent apply() calls for the SAME company — two calls racing with
     * DIFFERENT idempotency keys (two browser tabs, or Owner + Admin both on
     * the setup wizard) can no longer both read "no active run" and both
     * post; the second caller blocks on the lock until the first commits,
     * then correctly observes (and voids, as a re-run) the first's
     * now-active run. Mirrors BankAccountService::nextAccountCode's
     * per-company lockForUpdate. ALL active runs found are voided (not just
     * one), which is normally exactly one — but also recovers a pre-existing
     * duplicate left over from a race that predates this lock, instead of
     * leaving it permanently un-voidable.
     *
     * @param  array{
     *     books_start_date: string,
     *     bank_lines?: list<array{bank_account_id:int, amount:int}>,
     *     customer_lines?: list<array{contact_id:int, reference?:?string, original_date:string, due_date:string, amount:int}>,
     *     vendor_lines?: list<array{contact_id:int, reference?:?string, original_date:string, due_date:string, amount:int}>,
     * }  $data
     *
     * @throws OpeningBalancesLocked when a real transaction already exists and a re-run is attempted.
     */
    public function apply(string $idempotencyKey, array $data): OpeningBalanceRun
    {
        $result = $this->idempotency->execute($idempotencyKey, 'opening_balances.applied', function () use ($data) {
            return DB::transaction(function () use ($data) {
                // Lock the company row FIRST, before reading anything else —
                // see the class docblock above for why. The row itself isn't
                // used; only the lock it holds for the rest of this
                // transaction matters.
                Company::query()->whereKey($this->context->id())->lockForUpdate()->first();

                // Re-run handling FIRST, before anything new is created. Void
                // EVERY active run found — see class docblock.
                $priorRuns = OpeningBalanceRun::query()->where('status', 'active')->get();
                foreach ($priorRuns as $priorRun) {
                    $this->voidPriorRun($priorRun);
                }

                $run = OpeningBalanceRun::query()->create([
                    'books_start_date' => $data['books_start_date'],
                    'status' => 'active',
                    'created_by' => auth()->id(),
                ]);

                $this->context->get()->update(['books_start_date' => $data['books_start_date']]);

                [$lines, $documentCount] = $this->buildLinesAndDocuments($run, $data);

                // Entry dated the day BEFORE books_start_date (spec §6/§7.1):
                // opening balances must predate real activity.
                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['books_start_date'])->subDay(),
                    memo: 'Opening balances',
                    sourceType: 'opening_balance_run',
                    sourceId: $run->id,
                    lines: $lines,
                ));

                $this->audit->log('opening_balances.applied', $run, after: [
                    'entry_id' => $entry->id,
                    'books_start_date' => $data['books_start_date'],
                    'opening_document_count' => $documentCount,
                    'voided_run_ids' => $priorRuns->pluck('id')->all(),
                ]);

                return ['id' => $run->id];
            });
        });

        return OpeningBalanceRun::query()->findOrFail($result['id']);
    }

    /**
     * Voids one prior active run before a re-run creates its replacement:
     * reverses the run's own posted entry (mirror-image lines, same source,
     * entry_role=reversal) and marks the run + its opening documents void.
     * Refuses outright — leaving everything untouched — if a real
     * (non-opening) transaction has already posted (spec §7.1's "first real
     * transaction" rule): once that happens, setup is locked.
     *
     * Called once per active run apply() finds — normally that's a single
     * call, but if a pre-existing duplicate active run is found (a
     * leftover race artifact, see class docblock), this is called once per
     * run so every one of them gets voided, not just the first.
     */
    private function voidPriorRun(OpeningBalanceRun $priorRun): void
    {
        // "First real transaction" = any posted entry whose source is NOT an
        // opening_balance_run. Reversal entries keep their original source's
        // type (spec §4 iron rule), so this also correctly excludes a prior
        // run's own reversal — only genuinely different sources count.
        $hasRealTransaction = JournalEntry::query()
            ->where('source_type', '!=', 'opening_balance_run')
            ->exists();
        if ($hasRealTransaction) {
            throw new OpeningBalancesLocked('Opening balances are locked once real transactions exist.');
        }

        $priorEntry = JournalEntry::query()
            ->where('source_type', 'opening_balance_run')
            ->where('source_id', $priorRun->id)
            ->where('entry_role', EntryRole::Primary->value)
            ->firstOrFail();

        $this->ledger->reverse(
            $priorEntry,
            CarbonImmutable::parse($priorEntry->entry_date->toDateString()),
            'Opening balances re-run: voiding prior setup',
        );

        $priorRun->update(['status' => 'voided']);
        OpeningDocument::query()->where('opening_balance_run_id', $priorRun->id)->update(['status' => 'void']);
    }

    /**
     * Builds the ledger lines for the single combined P8a-c entry AND creates
     * the per-contact opening_documents rows (one per customer/vendor line).
     * Bank/cash lines have no document — P8a is a direct ledger balance, not
     * a per-contact subledger item.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: list<LineDraft>, 1: int} [lines, opening document count]
     */
    private function buildLinesAndDocuments(OpeningBalanceRun $run, array $data): array
    {
        $lines = [];
        $totalDebits = 0;
        $totalCredits = 0;
        $documentCount = 0;

        foreach ($data['bank_lines'] ?? [] as $bankLine) {
            if ($bankLine['amount'] <= 0) {
                continue;
            }
            // Scoped findOrFail: BankAccount's BelongsToCompany global scope
            // makes a foreign-company id indistinguishable from a
            // nonexistent one — defense-in-depth below
            // StoreOpeningBalanceRequest's own friendly existence check.
            $bankAccount = BankAccount::query()->findOrFail($bankLine['bank_account_id']);
            $lines[] = new LineDraft(accountId: $bankAccount->ledger_account_id, debit: $bankLine['amount'], credit: 0);
            $totalDebits += $bankLine['amount'];
        }

        $arAccount = null;
        foreach ($data['customer_lines'] ?? [] as $customerLine) {
            if ($customerLine['amount'] <= 0) {
                continue;
            }
            $arAccount ??= Account::query()->where('code', '1100')->firstOrFail();

            OpeningDocument::query()->create([
                'opening_balance_run_id' => $run->id,
                'contact_id' => $customerLine['contact_id'],
                'kind' => 'receivable',
                'reference' => $customerLine['reference'] ?? null,
                'original_date' => $customerLine['original_date'],
                'due_date' => $customerLine['due_date'],
                'amount' => $customerLine['amount'],
                'status' => 'open',
            ]);
            $documentCount++;

            // contact_id REQUIRED here — LedgerService rejects any AR/AP line
            // without one, and this is what makes Phase 5 aging tie out.
            $lines[] = new LineDraft(
                accountId: $arAccount->id, debit: $customerLine['amount'], credit: 0,
                contactId: $customerLine['contact_id'],
            );
            $totalDebits += $customerLine['amount'];
        }

        $apAccount = null;
        foreach ($data['vendor_lines'] ?? [] as $vendorLine) {
            if ($vendorLine['amount'] <= 0) {
                continue;
            }
            $apAccount ??= Account::query()->where('code', '2000')->firstOrFail();

            OpeningDocument::query()->create([
                'opening_balance_run_id' => $run->id,
                'contact_id' => $vendorLine['contact_id'],
                'kind' => 'payable',
                'reference' => $vendorLine['reference'] ?? null,
                'original_date' => $vendorLine['original_date'],
                'due_date' => $vendorLine['due_date'],
                'amount' => $vendorLine['amount'],
                'status' => 'open',
            ]);
            $documentCount++;

            $lines[] = new LineDraft(
                accountId: $apAccount->id, debit: 0, credit: $vendorLine['amount'],
                contactId: $vendorLine['contact_id'],
            );
            $totalCredits += $vendorLine['amount'];
        }

        // The Opening Balance Equity plug: exactly one line, whichever side
        // balances the rest. Normally assets+receivables exceed payables, so
        // OBE is credited — but the reverse is handled too. Omitted entirely
        // when debits already equal credits (a zero-amount line would fail
        // LedgerService's "exactly one side positive" rule).
        $diff = $totalDebits - $totalCredits;
        if ($diff !== 0) {
            $obeAccount = Account::query()->where('code', '3100')->firstOrFail();
            $lines[] = $diff > 0
                ? new LineDraft(accountId: $obeAccount->id, debit: 0, credit: $diff)
                : new LineDraft(accountId: $obeAccount->id, debit: -$diff, credit: 0);
        }

        return [$lines, $documentCount];
    }
}
