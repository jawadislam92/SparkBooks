<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\BillStatus;
use App\Enums\EntryRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\OpeningBalancesLocked;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
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
        private DocumentNumberService $numbers,
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
     * Phase 3 Task 6 (spec §6 P8b intends opening docs to BE invoices): each
     * customer_lines row also gets an is_opening Invoice (status Sent,
     * balance/total = amount, number from the 'OB' document-number series),
     * created straight to Sent with NO ledger posting of its own — the AR
     * line built here (P8b) already covers it; posting a second one would
     * double the receivable. The invoice is what makes the opening
     * receivable PAYABLE: InvoicePaymentService::recordPayment (Task 4)
     * settles it through the ordinary P2/P3 path, unmodified. Vendor
     * (payable) lines are unaffected — they stay opening_documents-only
     * until Phase 4 bills exist. See buildLinesAndDocuments() below and
     * .superpowers/sdd/task-6-report.md for the full design writeup.
     *
     * Phase 4 Task 7 (the exact AP-side mirror of Task 6): each vendor_lines
     * row ALSO gets an is_opening Bill (status Received, balance/total =
     * amount), created straight to Received with NO ledger posting of its
     * own — the AP line built here (P8c) already covers it. The bill is what
     * makes the opening payable PAYABLE: BillPaymentService::record (Phase 4
     * Task 5) settles it through the ordinary P7 path, unmodified. See
     * createOpeningBill() below and .superpowers/sdd/task-7-report.md.
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
     *
     * Task 6 addendum: also voids every is_opening invoice this run created
     * (found via opening_documents.invoice_id, since Invoice carries no
     * run reference of its own). The reversal above already wiped that AR
     * off the books, so these invoices must stop being payable — but since
     * an is_opening invoice never posted its own P1 (see
     * buildLinesAndDocuments()), there's no P9 to reverse for it; marking it
     * void directly is the correct mirror. This can never orphan a PAID
     * invoice: hasRealTransaction above already refuses the whole re-run
     * the moment any payment (source_type=invoice_payment) has posted
     * against one of this run's invoices, before any of this runs.
     *
     * Task 7 addendum: exact AP-side mirror of the Task 6 addendum above —
     * also voids every is_opening Bill this run created (found via
     * opening_documents.bill_id). Same reasoning throughout: the reversal
     * already wiped the AP off the books, an is_opening bill never posted
     * its own P6/P6t (see buildLinesAndDocuments()), so there's no P9 to
     * reverse — marking it void directly is correct — and hasRealTransaction
     * above already refuses the whole re-run once any payment
     * (source_type=bill_payment) has posted against one of this run's
     * bills, so a PAID bill can never be orphaned here either.
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

        $invoiceIds = OpeningDocument::query()
            ->where('opening_balance_run_id', $priorRun->id)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id');
        if ($invoiceIds->isNotEmpty()) {
            Invoice::query()->whereIn('id', $invoiceIds)->update([
                'status' => InvoiceStatus::Void->value,
                'voided_at' => now(),
            ]);
        }

        $billIds = OpeningDocument::query()
            ->where('opening_balance_run_id', $priorRun->id)
            ->whereNotNull('bill_id')
            ->pluck('bill_id');
        if ($billIds->isNotEmpty()) {
            Bill::query()->whereIn('id', $billIds)->update([
                'status' => BillStatus::Void->value,
                'voided_at' => now(),
            ]);
        }

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

            $openingInvoice = $this->createOpeningInvoice($customerLine);

            OpeningDocument::query()->create([
                'opening_balance_run_id' => $run->id,
                'contact_id' => $customerLine['contact_id'],
                'invoice_id' => $openingInvoice->id,
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

            $openingBill = $this->createOpeningBill($vendorLine);

            OpeningDocument::query()->create([
                'opening_balance_run_id' => $run->id,
                'contact_id' => $vendorLine['contact_id'],
                'bill_id' => $openingBill->id,
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

    /**
     * Creates the is_opening Invoice that represents one customer opening
     * receivable (Task 6). Deliberately created straight to Sent (never
     * through InvoiceService::send()) with NO ledger posting — the P8b line
     * built by the caller already covers this same amount, so this method
     * touches only the invoices/opening_documents tables. No invoice_lines
     * either: an opening balance is a lump-sum carry-in from the old books,
     * not an itemized sale, and there's no income account it would be
     * correct to attribute it to — see task-6-report.md.
     *
     * @param  array{contact_id:int, reference?:?string, original_date:string, due_date:string, amount:int}  $customerLine
     */
    private function createOpeningInvoice(array $customerLine): Invoice
    {
        $amount = $customerLine['amount'];

        return Invoice::query()->create([
            'contact_id' => $customerLine['contact_id'],
            'number' => $this->numbers->next('OB', 'OB'),
            'status' => InvoiceStatus::Sent->value,
            'issue_date' => $customerLine['original_date'],
            'due_date' => $customerLine['due_date'],
            'currency_code' => $this->context->get()->base_currency,
            'fx_rate' => 1,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'base_subtotal' => $amount,
            'base_tax_total' => 0,
            'base_total' => $amount,
            'is_opening' => true,
            'created_by' => auth()->id(),
            'sent_at' => now(),
        ]);
    }

    /**
     * Creates the is_opening Bill that represents one vendor opening payable
     * (Task 7) — the exact AP-side mirror of createOpeningInvoice() above.
     * Deliberately created straight to Received (never through
     * BillService::receive()) with NO ledger posting — the P8c line built by
     * the caller already covers this same amount, so this method touches
     * only the bills/opening_documents tables. No BillLine either, mirroring
     * createOpeningInvoice()'s identical "no invoice_lines" reasoning: an
     * opening balance is a lump-sum carry-in from the old books, not an
     * itemized purchase, and there's no category account it would be correct
     * to attribute it to.
     *
     * Deviation from the invoice mirror: `reference` IS carried over from
     * vendorLine here (unlike createOpeningInvoice(), which never sets a
     * `reference` on the Invoice it creates) — Bill has a `reference` column
     * for exactly this purpose ("the VENDOR's own bill number", see the
     * bills migration), whereas Invoice has no such column at all (its
     * `number` is the internally generated OB-series identifier, never the
     * vendor/customer's own reference). There is nothing to mirror on the
     * invoice side because the field doesn't exist there.
     *
     * @param  array{contact_id:int, reference?:?string, original_date:string, due_date:string, amount:int}  $vendorLine
     */
    private function createOpeningBill(array $vendorLine): Bill
    {
        $amount = $vendorLine['amount'];

        return Bill::query()->create([
            'contact_id' => $vendorLine['contact_id'],
            'reference' => $vendorLine['reference'] ?? null,
            'status' => BillStatus::Received->value,
            'bill_date' => $vendorLine['original_date'],
            'due_date' => $vendorLine['due_date'],
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'is_opening' => true,
            'created_by' => auth()->id(),
            'received_at' => now(),
        ]);
    }
}
