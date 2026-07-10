<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\IntegrityRun;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Models\User;
use App\Services\BillService;
use App\Services\ExpenseService;
use App\Services\IntegrityChecker;
use App\Services\LedgerService;
use App\Services\OpeningBalanceService;
use App\Services\OtherReceiptService;
use App\Services\TransferService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * §9 correctness watchdog: App\Services\IntegrityChecker, the
 * `ledger:verify` command (App\Console\Commands\VerifyLedgerIntegrity), and
 * the Settings > Health screen (App\Http\Controllers\HealthController).
 *
 * The property under test throughout is "fails loudly, never passes
 * vacuously" — every corruption scenario is seeded by RAW SQL that bypasses
 * the model guards (BelongsToCompany scoping, ImmutableLedger, etc.), since
 * going through the normal write path (LedgerService/ManualJournalService)
 * would simply refuse to create the corrupt state in the first place.
 */
class IntegrityCheckerTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function receivableAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ], $overrides));
    }

    private function equityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3000',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ], $overrides));
    }

    /**
     * A BankAccount MODEL (distinct from the plain ledger Account created by
     * bankAccount() above) — the linked-Account nested factory needs a live
     * CompanyContext to fill its own company_id, hence the runFor wrap.
     * Mirrors TransfersTest/OtherReceiptsTest/OpeningBalancesTest.
     */
    private function bankAccountModel(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Posts a mirror-image reversal of an existing entry via LedgerService
     * directly — source-type agnostic, exactly like ManualJournalService and
     * OpeningBalanceService's own void path do internally. Used here to
     * reverse transfer/other_receipt-sourced entries too, since those
     * services don't (yet) expose their own reverse() method but the
     * underlying ledger mechanism (same source_type/source_id, entry_role=
     * reversal) is identical regardless of which service originally posted.
     */
    private function reverseEntry(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->reverse(
            $entry, CarbonImmutable::parse('2026-07-02'), 'Test reversal'
        ));
    }

    /**
     * Posts a genuinely balanced entry straight through LedgerService (the
     * one gate into the ledger), with a real ManualJournal document backing
     * it — bypassing HTTP/auth/idempotency entirely, since these tests only
     * care about the ledger state the checker sees afterward. Sets
     * CompanyContext to $company as a side effect (matches how every
     * BelongsToCompany write is scoped).
     *
     * @param  list<array{account_id:int, debit:int, credit:int}>  $lines
     */
    private function postBalancedEntry(Company $company, array $lines, ?int $sourceId = null): JournalEntry
    {
        app(CompanyContext::class)->set($company);

        $doc = ManualJournal::query()->create([
            'entry_date' => '2026-07-01',
            'memo' => 'Test entry',
            'status' => 'posted',
        ]);

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Test entry',
            sourceType: 'manual_journal',
            sourceId: $sourceId ?? $doc->id,
            lines: array_map(fn ($l) => new LineDraft(
                accountId: $l['account_id'],
                debit: $l['debit'],
                credit: $l['credit'],
            ), $lines),
        );

        return DB::transaction(fn () => app(LedgerService::class)->post($draft));
    }

    /** Raw-inserts a journal_line, bypassing LedgerService/model guards entirely. */
    private function rawInsertLine(Company $company, JournalEntry $entry, Account $account, int $debit, int $credit, ?int $contactId = null): void
    {
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'company_id' => $company->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => null,
            'contact_id' => $contactId,
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers — Task 12 (invoice/payment source coverage + check (c) split).
    // Mirror InvoiceFxSendTest/InvoiceFxPaymentTest/InvoiceVoidTest's own
    // helper shapes so the real send()/recordPayment()/void() HTTP flows
    // post genuine P1/P1fx/P2/P2fx/P9 ledger entries — the whole point of
    // these tests is that IntegrityChecker sees exactly what those services
    // actually post, not a hand-rolled approximation of it.
    // ---------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function customer(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ], $overrides));
    }

    private function taxPayableAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
            'is_system' => true,
        ]);
    }

    private function fxGainAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4950',
            'name' => 'FX Gain',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
            'is_system' => true,
        ]);
    }

    private function fxLossAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5950',
            'name' => 'FX Loss',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'is_system' => true,
        ]);
    }

    /**
     * Standard chart these invoice tests depend on: 1100 AR, 2100 Tax
     * Payable, 4950/5950 FX gain/loss. receivableAccount() (above) already
     * creates 1100 with the right subtype, so it's reused here rather than
     * duplicated.
     *
     * @return array{ar: Account, taxPayable: Account, fxGain: Account, fxLoss: Account}
     */
    private function invoiceChart(Company $company): array
    {
        return [
            'ar' => $this->receivableAccount($company),
            'taxPayable' => $this->taxPayableAccount($company),
            'fxGain' => $this->fxGainAccount($company),
            'fxLoss' => $this->fxLossAccount($company),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function domesticDraftInvoice(Company $company, Contact $contact, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => 'PKR',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function usdDraftInvoice(Company $company, Contact $contact, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => 'USD',
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor — InvoiceLine::booted()'s freeze
     * guard needs a live CompanyContext on every create (mirrors
     * InvoiceFxSendTest's identical helper).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoiceLine(Invoice $invoice, Account $incomeAccount, array $overrides = []): InvoiceLine
    {
        $company = Company::query()->findOrFail($invoice->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => InvoiceLine::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'income_account_id' => $incomeAccount->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $body */
    private function sendInvoice(Invoice $invoice, array $body = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/send", $body, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function pay(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function voidInvoice(Invoice $invoice, array $data = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/void", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidPayment(Invoice $invoice, InvoicePayment $payment, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments/{$payment->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: clean company
    // ---------------------------------------------------------------

    public function test_clean_company_yields_no_findings(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: seeded corruption
    // ---------------------------------------------------------------

    public function test_raw_tampered_line_produces_entry_balance_and_trial_balance_findings(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Model guards (JournalLine::booted() throws ImmutableLedger on
        // update) forbid this through Eloquent — raw SQL is the only way to
        // seed this corruption, which is exactly the point of the test.
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        $balanceFinding = collect($findings)->firstWhere('check', 'entry_balance');
        $this->assertStringContainsString('5000', $balanceFinding['detail']);
    }

    public function test_contactless_control_account_line_produces_finding_without_tripping_balance_checks(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $receivable = $this->receivableAccount($company);

        $entry = $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Add an offsetting pair of raw lines so the entry (and the trial
        // balance) still nets to zero — isolating this test to JUST the
        // missing-contact defect, not an incidental balance defect too.
        $this->rawInsertLine($company, $entry, $receivable, debit: 500, credit: 0, contactId: null);
        $this->rawInsertLine($company, $entry, $bank, debit: 0, credit: 500);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('control_contact', $checks);
        $this->assertNotContains('entry_balance', $checks);
        $this->assertNotContains('trial_balance', $checks);
    }

    public function test_orphaned_manual_journal_source_entry_produces_finding(): void
    {
        $company = Company::factory()->create();

        // A ledger entry claiming to come from a manual journal document
        // that doesn't exist (id 999999). No matching lines are needed —
        // this exercises the orphan_entries check in isolation.
        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'manual_journal',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('orphan_entries', $checks);
    }

    // ---------------------------------------------------------------
    // IntegrityChecker: Phase 2 source-type-aware orphan check
    // ---------------------------------------------------------------

    /**
     * Raw-inserts a JournalEntry with the given source_type and a source_id
     * (999999) that has no matching row in that type's table, and asserts an
     * orphan_entries finding naming the source_type shows up. Shared by the
     * three Phase 2 source-type tests below — mirrors
     * test_orphaned_manual_journal_source_entry_produces_finding above, one
     * per new source type registered in IntegrityChecker::SOURCE_TABLES.
     */
    private function assertOrphanDetectedForSourceType(string $sourceType): void
    {
        $company = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => $sourceType,
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('orphan_entries', $checks);
        $finding = collect($findings)->firstWhere('check', 'orphan_entries');
        $this->assertStringContainsString($sourceType, $finding['detail']);
    }

    public function test_orphaned_transfer_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('transfer');
    }

    public function test_orphaned_other_receipt_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('other_receipt');
    }

    public function test_orphaned_opening_balance_run_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('opening_balance_run');
    }

    public function test_orphaned_expense_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('expense');
    }

    public function test_orphaned_bill_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('bill');
    }

    /**
     * The point of the whole extension: a source_type this checker has never
     * heard of (a stand-in for some future document type someone forgets to
     * register in IntegrityChecker::SOURCE_TABLES — 'bill_payment' is the
     * current genuine example: BillPayment's schema/model already exist as
     * of Phase 4 T1, but no service posts through it yet, so this source
     * type is real future work, not a fictional placeholder) must fail loud
     * — a distinct 'unrecognized_source' finding — rather than being
     * silently skipped by the per-source-type loop. ('invoice' was the
     * original stand-in until Task 12 registered it; 'bill' was the stand-in
     * from Task 12 through Phase 4 T2 until THIS task registered it too —
     * see the expense/bill orphan tests above.)
     */
    public function test_unknown_source_type_produces_unrecognized_source_finding(): void
    {
        $company = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Future source type (raw insert)',
            'source_type' => 'bill_payment',
            'source_id' => 1,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('unrecognized_source', $checks);
        $this->assertNotContains('orphan_entries', $checks);
        $finding = collect($findings)->firstWhere('check', 'unrecognized_source');
        $this->assertStringContainsString('bill_payment', $finding['detail']);
    }

    /**
     * Reversal entries keep the ORIGINAL entry's source_type/source_id (spec
     * §4 iron rule) — they must never be mistaken for orphans (their source
     * row genuinely exists) or for an unrecognized source_type.
     */
    public function test_reversal_of_a_transfer_entry_is_not_flagged_as_orphan_or_unrecognized(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $from = $this->bankAccountModel($company);
        $to = $this->bankAccountModel($company);

        $transfer = app(TransferService::class)->transfer((string) Str::uuid(), [
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'transfer_date' => '2026-07-01',
            'amount' => 5000,
            'memo' => 'Test transfer',
        ]);
        $entry = JournalEntry::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->sole();
        $this->reverseEntry($entry);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertNotContains('orphan_entries', $checks);
        $this->assertNotContains('unrecognized_source', $checks);
    }

    /**
     * A company with a live document + reversal pair for EVERY Phase 2
     * source type (manual journal, transfer, other receipt, opening balance
     * run) must come back completely clean — the key assertion being that
     * none of the four reversals gets mistaken for an orphan just because
     * it shares its source_id with a document that ISN'T itself.
     */
    public function test_clean_multi_source_company_with_reversals_yields_no_findings(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $bankA = $this->bankAccountModel($company);
        $bankB = $this->bankAccountModel($company);
        $income = $this->incomeAccount($company);
        $equity = $this->equityAccount($company);
        $obe = $this->equityAccount($company, ['code' => '3100']);

        // manual_journal + reversal
        $mjEntry = $this->postBalancedEntry($company, [
            ['account_id' => $bankA->ledger_account_id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $this->reverseEntry($mjEntry);

        // transfer + reversal
        $transfer = app(TransferService::class)->transfer((string) Str::uuid(), [
            'from_bank_account_id' => $bankA->id,
            'to_bank_account_id' => $bankB->id,
            'transfer_date' => '2026-07-01',
            'amount' => 5000,
            'memo' => 'Test transfer',
        ]);
        $transferEntry = JournalEntry::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->sole();
        $this->reverseEntry($transferEntry);

        // other_receipt + reversal
        $receipt = app(OtherReceiptService::class)->record((string) Str::uuid(), [
            'bank_account_id' => $bankA->id,
            'category_account_id' => $equity->id,
            'receipt_date' => '2026-07-01',
            'amount' => 3000,
            'payer' => 'Test payer',
            'memo' => 'Test receipt',
        ]);
        $receiptEntry = JournalEntry::query()->where('source_type', 'other_receipt')->where('source_id', $receipt->id)->sole();
        $this->reverseEntry($receiptEntry);

        // opening_balance_run + reversal
        $run = app(OpeningBalanceService::class)->apply((string) Str::uuid(), [
            'books_start_date' => '2026-06-01',
            'bank_lines' => [
                ['bank_account_id' => $bankA->id, 'amount' => 20000],
            ],
        ]);
        $runEntry = JournalEntry::query()->where('source_type', 'opening_balance_run')->where('source_id', $run->id)->sole();
        $this->reverseEntry($runEntry);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Multi-company isolation for the NEW source-type-aware check
     * specifically (the existing isolation test below covers entry_balance).
     * Tampering company A with an orphaned transfer-sourced entry must not
     * leak into company B's findings.
     */
    public function test_orphan_finding_for_a_phase2_source_type_is_isolated_to_the_tampered_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        DB::table('journal_entries')->insert([
            'company_id' => $companyA->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'transfer',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postBalancedEntry($companyB, [
            ['account_id' => $bankB->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $incomeB->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $findingsA = app(IntegrityChecker::class)->checkCompany($companyA);
        $findingsB = app(IntegrityChecker::class)->checkCompany($companyB);

        $this->assertContains('orphan_entries', collect($findingsA)->pluck('check')->all());
        $this->assertSame([], $findingsB);
    }

    // ---------------------------------------------------------------
    // Task 12: source coverage for 'invoice' / 'invoice_payment'.
    // ---------------------------------------------------------------

    public function test_orphaned_invoice_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('invoice');
    }

    public function test_orphaned_invoice_payment_source_entry_produces_finding(): void
    {
        $this->assertOrphanDetectedForSourceType('invoice_payment');
    }

    /**
     * A voided (reversed) invoice keeps the SAME source_type/source_id on
     * its reversal entry (spec §4 iron rule) — must never be mistaken for an
     * orphan or an unrecognized source, exactly like the Phase 2 source
     * types already prove for transfer/other_receipt/opening_balance_run.
     */
    public function test_reversal_of_an_invoice_entry_is_not_flagged_as_orphan_or_unrecognized(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice)->assertRedirect();
        $this->voidInvoice($invoice->fresh(), ['memo' => 'Cancelled'])->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertNotContains('orphan_entries', $checks);
        $this->assertNotContains('unrecognized_source', $checks);
        // A voided invoice is fully excluded from check (c) too (see
        // POSTED_INVOICE_STATUSES) — nothing left to flag at all.
        $this->assertSame([], $findings);
    }

    /**
     * Same property, payment side: voidPayment()'s reversal keeps the
     * ORIGINAL payment's source_id — must not be mistaken for an orphan.
     * The invoice itself stays Sent (not void) afterward, so this also
     * proves the ledger-side base-AR sum correctly nets a voided payment's
     * primary+reversal pair back to zero (the invoice's own base_total is
     * the only thing left contributing).
     */
    public function test_reversal_of_an_invoice_payment_entry_is_not_flagged_as_orphan_or_unrecognized(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice)->assertRedirect();
        $invoice = $invoice->fresh();

        $this->pay($invoice, ['amount' => 4000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-05'])->assertRedirect();
        $payment = InvoicePayment::query()->sole();
        $this->voidPayment($invoice->fresh(), $payment)->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertNotContains('orphan_entries', $checks);
        $this->assertNotContains('unrecognized_source', $checks);
        // The invoice is back to Sent/fully-unpaid — balance and base AR
        // both reconcile exactly as if the payment never happened.
        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // Phase 4 T3/T4: source coverage for 'expense' / 'bill' — the real
    // documents that finally landed behind the 'bill' stand-in the
    // unrecognized-source test above used to use. Mirrors the Task 12
    // invoice/invoice_payment section: post a genuine P4/P5 expense and a
    // genuine P6/P6t bill straight through their real services, and prove
    // IntegrityChecker sees exactly what those services posted — no
    // orphan/unrecognized false positive now that SOURCE_TABLES registers
    // both.
    // ---------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    public function test_clean_company_with_a_recorded_expense_and_a_received_bill_yields_no_findings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        // ExpenseService/BillService are called directly below (not via
        // HTTP), so CompanyContext needs to be set explicitly — mirrors
        // test_clean_multi_source_company_with_reversals_yields_no_findings'
        // own identical reasoning for TransferService/OtherReceiptService.
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccountModel($company);
        $vendor = $this->customer($company, ['type' => ContactType::Vendor->value]);
        $expenseCategory = $this->categoryAccount($company, ['code' => '5000']);
        $billCategory = $this->categoryAccount($company, ['code' => '5010']);
        $this->taxPayableAccount($company);
        // Seeds the 2000 AP account BillService::p6Lines resolves by code —
        // not otherwise referenced below.
        Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
            'is_system' => true,
        ]);

        $expense = Expense::factory()->create([
            'company_id' => $company->id,
            'status' => 'draft',
            'bank_account_id' => $bank->id,
        ]);
        app(CompanyContext::class)->runFor($company, fn () => ExpenseLine::factory()->create([
            'expense_id' => $expense->id,
            'company_id' => $company->id,
            'category_account_id' => $expenseCategory->id,
            'amount' => 15000,
            'tax_rate_id' => null,
        ]));
        app(ExpenseService::class)->record((string) Str::uuid(), $expense);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'status' => 'draft',
            'due_date' => '2026-08-01',
        ]);
        app(CompanyContext::class)->runFor($company, fn () => BillLine::factory()->create([
            'bill_id' => $bill->id,
            'company_id' => $company->id,
            'category_account_id' => $billCategory->id,
            'amount' => 22000,
            'tax_rate_id' => null,
        ]));
        app(BillService::class)->receive((string) Str::uuid(), $bill);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // Task 12: check (c) split — document_balance + base_ar_reconciliation.
    // ---------------------------------------------------------------

    /**
     * The comprehensive no-false-positive test: a company with domestic AND
     * FX invoices spanning every lifecycle stage (draft, sent-unpaid,
     * partially-paid, paid, void, and a voided-then-refinal-paid payment)
     * must come back COMPLETELY clean. This is the property that matters
     * most for check (c) — a checker that cries wolf on correctly-posted FX
     * invoices is worse than no checker (spec §9: "expected result forever:
     * zero findings").
     */
    public function test_clean_company_with_domestic_and_fx_invoices_across_full_lifecycle_yields_no_findings(): void
    {
        $company = Company::factory()->create(); // base_currency PKR
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        // Draft — never sent, no ledger entries at all.
        $draft = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($draft, $income, ['quantity' => '1', 'unit_price' => 5000, 'tax_rate_id' => null]);

        // Domestic: sent, unpaid.
        $sentUnpaid = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($sentUnpaid, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($sentUnpaid)->assertRedirect();

        // Domestic: partially paid.
        $partial = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($partial, $income, ['quantity' => '1', 'unit_price' => 20000, 'tax_rate_id' => null]);
        $this->sendInvoice($partial)->assertRedirect();
        $this->pay($partial->fresh(), ['amount' => 8000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-05'])->assertRedirect();

        // Domestic: paid in full.
        $paidDomestic = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($paidDomestic, $income, ['quantity' => '1', 'unit_price' => 15000, 'tax_rate_id' => null]);
        $this->sendInvoice($paidDomestic)->assertRedirect();
        $this->pay($paidDomestic->fresh(), ['amount' => 15000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-06'])->assertRedirect();

        // Domestic: voided (no payments — the direct void path).
        $voided = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($voided, $income, ['quantity' => '1', 'unit_price' => 3000, 'tax_rate_id' => null]);
        $this->sendInvoice($voided)->assertRedirect();
        $this->voidInvoice($voided->fresh(), ['memo' => 'Cancelled'])->assertRedirect();

        // FX: sent, unpaid.
        $fxSent = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($fxSent, $income, ['quantity' => '1', 'unit_price' => 50000, 'tax_rate_id' => null]);
        $this->sendInvoice($fxSent, ['fx_rate' => '280'])->assertRedirect();

        // FX: partially paid, with a genuine FX gain plug on the partial.
        $fxPartial = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($fxPartial, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($fxPartial, ['fx_rate' => '280'])->assertRedirect();
        $this->pay($fxPartial->fresh(), [
            'amount' => 40000, 'base_amount' => 11300000, // relief 11,200,000 + 100,000 gain
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-07',
        ])->assertRedirect();

        // FX: paid in full — the spec §17.1 worked example (gain).
        $fxPaid = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($fxPaid, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($fxPaid, ['fx_rate' => '280'])->assertRedirect();
        $this->pay($fxPaid->fresh(), [
            'amount' => 100000, 'base_amount' => 28500000, // relief 28,000,000 + 500,000 gain
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-08',
        ])->assertRedirect();

        // FX: a voided partial excluded from a later FINAL payment's
        // settlement-rounding sum, then paid in full with a gain — the most
        // adversarial base-AR shape this check has to tolerate.
        $refinal = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($refinal, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($refinal, ['fx_rate' => '280'])->assertRedirect();
        $refinal = $refinal->fresh();
        $this->pay($refinal, [
            'amount' => 40000, 'base_amount' => 11300000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10',
        ])->assertRedirect();
        $voidedPartial = InvoicePayment::query()->where('invoice_id', $refinal->id)->sole();
        $this->voidPayment($refinal->fresh(), $voidedPartial)->assertRedirect();
        $this->pay($refinal->fresh(), [
            'amount' => 100000, 'base_amount' => 28600000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-11',
        ])->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Standalone worked example (spec §17.1, exactly): USD 1,000 @ 280,
     * base_total 28,000,000, paid in full with the bank crediting 28,500,000
     * -> a 500,000 FX gain plug. The base-AR tie-out must be tolerant of
     * that plug by construction (it lives on 4950, never touched by the AR
     * query) — isolated here from the bigger lifecycle test above so the
     * property is independently verifiable.
     */
    public function test_fully_paid_fx_invoice_with_correct_fx_gain_plug_is_clean(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, ['fx_rate' => '280'])->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(28000000, $invoice->base_total);

        $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * The settlement-rounding property under check (c): three payments at
     * fx_rate 1.005 where independent per-payment rounding leaves a residue,
     * and the FINAL payment relieves the ACTUAL remaining base AR rather
     * than round(rate x amount) — mirrors
     * InvoiceFxPaymentTest::test_settlement_rounding_the_final_payment_zeroes_base_ar_exactly_despite_partial_rounding_residue.
     * If check (c) naively re-derived expected base AR per-payment instead
     * of trusting each payment's own stored base_ar_relief, THIS is the
     * scenario that would produce a false positive.
     */
    public function test_fully_paid_fx_invoice_with_settlement_rounding_residue_is_clean(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 300, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, ['fx_rate' => '1.005'])->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(302, $invoice->base_total);

        $this->pay($invoice, ['amount' => 100, 'base_amount' => 101, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $this->pay($invoice->fresh(), ['amount' => 100, 'base_amount' => 101, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12'])->assertRedirect();
        $this->pay($invoice->fresh(), ['amount' => 100, 'base_amount' => 100, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-14'])->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(300, $invoice->amount_paid);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * document_balance: raw-tamper an invoice's stored `balance` (bypassing
     * InvoicePaymentService entirely) so it disagrees with total minus
     * non-void payments.
     */
    public function test_document_balance_finding_when_invoice_balance_disagrees_with_payments(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice)->assertRedirect();
        $this->pay($invoice->fresh(), ['amount' => 4000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-05'])->assertRedirect();

        // Correct balance would be 10000 - 4000 = 6000.
        DB::table('invoices')->where('id', $invoice->id)->update(['balance' => 9999]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('document_balance', $checks);
        $finding = collect($findings)->firstWhere('check', 'document_balance');
        $this->assertStringContainsString((string) $invoice->id, $finding['detail']);
        $this->assertStringContainsString('9999', $finding['detail']);
        $this->assertStringContainsString('6000', $finding['detail']);
    }

    /**
     * base_ar_reconciliation, branch 1 (the fully-paid invariant): tamper
     * base_total directly on a PAID invoice, leaving the ledger and the
     * payments table untouched. expected base AR (base_total minus
     * non-void payments' base_ar_relief) becomes non-zero even though the
     * ledger and the payments table are still mutually self-consistent —
     * exactly the corruption shape the explicit "Paid => 0" assertion
     * exists to catch (a plain expected-vs-ledger comparison alone
     * wouldn't: see the class docblock).
     */
    public function test_base_ar_reconciliation_finding_when_a_paid_invoices_base_total_is_tampered(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, ['fx_rate' => '280'])->assertRedirect();
        $invoice = $invoice->fresh();
        $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ])->assertRedirect();

        DB::table('invoices')->where('id', $invoice->id)->update(['base_total' => 28000100]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('base_ar_reconciliation', $checks);
        $finding = collect($findings)->firstWhere('check', 'base_ar_reconciliation');
        $this->assertStringContainsString('fully paid', $finding['detail']);
    }

    /**
     * base_ar_reconciliation, branch 2 (ledger-vs-expected mismatch):
     * raw-delete a live payment's own AR ledger line, leaving the
     * invoice_payments row (and its base_ar_relief) untouched. The
     * business-level "expected" side never moves, but the ledger's actual
     * base AR contribution for this invoice now disagrees with it.
     */
    public function test_base_ar_reconciliation_finding_when_a_payments_ar_ledger_line_is_raw_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->invoiceChart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccountModel($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, ['fx_rate' => '280'])->assertRedirect();
        $invoice = $invoice->fresh();
        $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $payment = InvoicePayment::query()->sole();
        $paymentEntry = JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)->sole();
        $arLine = $paymentEntry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);

        DB::table('journal_lines')->where('id', $arLine->id)->delete();

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('base_ar_reconciliation', $checks);
        $finding = collect($findings)->firstWhere('check', 'base_ar_reconciliation');
        $this->assertStringContainsString('ledger shows', $finding['detail']);
    }

    /**
     * Multi-company isolation for check (c) specifically: tampering company
     * A's invoice balance must not leak findings into company B.
     */
    public function test_document_balance_and_base_ar_reconciliation_findings_are_isolated_to_the_tampered_company(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $contactA = $this->customer($companyA);
        $this->invoiceChart($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $bankA = $this->bankAccountModel($companyA);

        $invoiceA = $this->domesticDraftInvoice($companyA, $contactA);
        $this->invoiceLine($invoiceA, $incomeA, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoiceA)->assertRedirect();
        DB::table('invoices')->where('id', $invoiceA->id)->update(['balance' => 1]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $contactB = $this->customer($companyB);
        $this->invoiceChart($companyB);
        $incomeB = $this->incomeAccount($companyB);

        $invoiceB = $this->domesticDraftInvoice($companyB, $contactB);
        $this->invoiceLine($invoiceB, $incomeB, ['quantity' => '1', 'unit_price' => 7500, 'tax_rate_id' => null]);
        $this->sendInvoice($invoiceB)->assertRedirect();

        $findingsA = app(IntegrityChecker::class)->checkCompany($companyA);
        $findingsB = app(IntegrityChecker::class)->checkCompany($companyB);

        $this->assertContains('document_balance', collect($findingsA)->pluck('check')->all());
        $this->assertSame([], $findingsB);
    }

    /**
     * A voided invoice's `balance`/`base_total` columns are never touched by
     * InvoiceService::void() (only status/voided_at flip) — so they stay
     * "stale" relative to the send-time total/payments, by design (spec §9:
     * a void invoice's ledger contribution already nets to zero via its own
     * reversal; check (c) simply doesn't apply once voided). Proven here by
     * deliberately setting balance to something that would fail the formula
     * if this invoice were still checked, and confirming it is not.
     */
    public function test_document_balance_check_ignores_a_voided_invoices_stale_balance(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice)->assertRedirect();
        $this->voidInvoice($invoice->fresh(), ['memo' => 'Cancelled'])->assertRedirect();

        DB::table('invoices')->where('id', $invoice->id)->update(['balance' => 123456]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // Task 13 (Phase 3 exit): opening invoices' AR is attributed by
    // contact_id, not by source_id=invoice_id — discovered live in the
    // exit-verification browser walkthrough (paying a fresh opening
    // invoice to Paid produced a false base_ar_reconciliation finding).
    // ---------------------------------------------------------------

    /**
     * An is_opening invoice never posts its own source_type='invoice'
     * entry — OpeningBalanceService::createOpeningInvoice() deliberately
     * never calls LedgerService (Task 6): the AR was already posted once,
     * by the ORIGINAL opening_balance_run's P8b line, which is keyed by
     * contact_id, not by any invoice id. Before this fix,
     * actualLedgerBaseArByInvoice() had no way to find that debit for an
     * is_opening invoice at all, so paying one down to Paid produced a
     * FALSE base_ar_reconciliation finding even though the ledger itself
     * was perfectly correct — proven independently below via the global
     * ledger net-zero assertion, so this is purely a checker attribution
     * gap, not a real corruption being masked.
     */
    public function test_clean_when_an_opening_invoice_is_fully_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $this->equityAccount($company, ['code' => '3100', 'name' => 'Opening Balance Equity']);
        $bank = $this->bankAccountModel($company);

        app(CompanyContext::class)->set($company);
        app(OpeningBalanceService::class)->apply((string) Str::uuid(), [
            'books_start_date' => '2026-06-01',
            'bank_lines' => [],
            'customer_lines' => [
                ['contact_id' => $contact->id, 'reference' => 'Opening INV', 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 500000],
            ],
            'vendor_lines' => [],
        ]);

        $invoice = Invoice::query()->where('is_opening', true)->sole();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $this->pay($invoice, ['amount' => 500000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);

        // Independent proof the LEDGER itself is genuinely correct, not
        // just that the checker was fooled into agreeing with it: the
        // whole ledger still nets to zero.
        $net = (int) DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS n')->value('n');
        $this->assertSame(0, $net);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $this->assertSame([], $findings);
    }

    /**
     * Same gap, the other checkInvoiceBalances() branch: a still-open
     * (partially paid, not yet Paid) opening invoice falls into the
     * expected-vs-ledger `elseif`, which depended on the same broken
     * attribution.
     */
    public function test_clean_when_an_opening_invoice_is_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->invoiceChart($company);
        $this->equityAccount($company, ['code' => '3100', 'name' => 'Opening Balance Equity']);
        $bank = $this->bankAccountModel($company);

        app(CompanyContext::class)->set($company);
        app(OpeningBalanceService::class)->apply((string) Str::uuid(), [
            'books_start_date' => '2026-06-01',
            'bank_lines' => [],
            'customer_lines' => [
                ['contact_id' => $contact->id, 'reference' => 'Opening INV', 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 500000],
            ],
            'vendor_lines' => [],
        ]);

        $invoice = Invoice::query()->where('is_opening', true)->sole();
        $this->pay($invoice, ['amount' => 200000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(300000, $invoice->balance);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $this->assertSame([], $findings);
    }

    // ---------------------------------------------------------------
    // Whole-branch review fix: a contact with MULTIPLE opening invoices in
    // ONE opening-balance run. OpeningBalanceService/StoreOpeningBalanceRequest
    // do not dedup customer_lines by contact, and the UI freely allows
    // multiple rows per customer (a realistic cutover: a customer with two
    // old unpaid invoices) — so this is reachable today. Before the fix,
    // actualLedgerBaseArByInvoice()'s `keyBy('contact_id')` silently dropped
    // all but the last opening invoice for a contact, then attributed the
    // WHOLE combined opening-run AR total to just that surviving invoice —
    // every OTHER opening invoice for that contact then compared its
    // (non-zero) expected base AR against a ledger contribution of 0,
    // producing a permanent false-positive base_ar_reconciliation finding
    // on correctly-posted data. The fix reconciles opening invoices at the
    // CONTACT level (both sides summed over the same set) instead.
    // ---------------------------------------------------------------

    /**
     * Posts two customer_lines for the SAME contact in one apply() call —
     * exactly the shape the opening-balances UI allows and neither
     * OpeningBalanceService nor StoreOpeningBalanceRequest dedups against.
     *
     * @return array{0: Contact, 1: Account, 2: BankAccount, 3: list<Invoice>}
     */
    private function setUpContactWithTwoOpeningInvoices(Company $company): array
    {
        $contact = $this->customer($company);
        $chart = $this->invoiceChart($company);
        $this->equityAccount($company, ['code' => '3100', 'name' => 'Opening Balance Equity']);
        $bank = $this->bankAccountModel($company);

        app(CompanyContext::class)->set($company);
        app(OpeningBalanceService::class)->apply((string) Str::uuid(), [
            'books_start_date' => '2026-06-01',
            'bank_lines' => [],
            'customer_lines' => [
                ['contact_id' => $contact->id, 'reference' => 'Old INV 1', 'original_date' => '2026-04-01', 'due_date' => '2026-05-01', 'amount' => 300000],
                ['contact_id' => $contact->id, 'reference' => 'Old INV 2', 'original_date' => '2026-04-15', 'due_date' => '2026-05-15', 'amount' => 200000],
            ],
            'vendor_lines' => [],
        ]);

        $invoices = Invoice::query()->where('is_opening', true)->orderBy('id')->get()->all();
        $this->assertCount(2, $invoices);

        return [$contact, $chart['ar'], $bank, $invoices];
    }

    /**
     * Test 1 (the false-positive reproduction): two correctly-posted, both
     * still-unpaid opening invoices for one contact must come back CLEAN.
     * This is the case that FAILS before the fix (keyBy drops one invoice,
     * whose full expected base AR then compares against a ledger 0).
     */
    public function test_clean_when_a_contact_has_two_unpaid_opening_invoices_in_one_run(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->setUpContactWithTwoOpeningInvoices($company);

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Test 2: both of the contact's opening invoices paid to Paid via
     * P2/P3 — the contact's aggregate opening AR nets to exactly zero.
     */
    public function test_clean_when_both_of_a_contacts_two_opening_invoices_are_paid_in_full(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        [, , $bank, $invoices] = $this->setUpContactWithTwoOpeningInvoices($company);

        foreach ($invoices as $invoice) {
            $this->pay($invoice, ['amount' => $invoice->total, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        }

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Test 3: one of the two opening invoices partially paid, the other
     * left fully unpaid — the aggregate still reconciles.
     */
    public function test_clean_when_one_of_two_opening_invoices_is_partially_paid_and_the_other_unpaid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        [, , $bank, $invoices] = $this->setUpContactWithTwoOpeningInvoices($company);

        $this->pay($invoices[0], ['amount' => 100000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    /**
     * Test 4a: a genuine tamper (raw-update an is_opening invoice's
     * base_total, leaving the ledger untouched) on a multi-opening-invoice
     * contact must still fire — no false-negative introduced by summing.
     */
    public function test_base_ar_reconciliation_fires_when_one_of_two_opening_invoices_base_total_is_tampered(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        [, , , $invoices] = $this->setUpContactWithTwoOpeningInvoices($company);

        DB::table('invoices')->where('id', $invoices[0]->id)->update(['base_total' => $invoices[0]->base_total + 500]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('base_ar_reconciliation', $checks);
    }

    /**
     * Test 4b: a genuine tamper on the LEDGER side instead — raw-delete one
     * of the contact's two opening_balance_run AR debit lines, leaving both
     * invoices' own rows untouched. The aggregate ledger sum for the
     * contact now falls short of the aggregate expected sum.
     */
    public function test_base_ar_reconciliation_fires_when_one_contacts_opening_ar_ledger_line_is_raw_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        [, $arAccount] = $this->setUpContactWithTwoOpeningInvoices($company);

        $runEntry = JournalEntry::query()->where('source_type', 'opening_balance_run')->sole();
        $arLines = $runEntry->lines()->where('account_id', $arAccount->id)->get();
        $this->assertCount(2, $arLines);

        DB::table('journal_lines')->where('id', $arLines->first()->id)->delete();

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('base_ar_reconciliation', $checks);
    }

    /**
     * Test 5: a contact with BOTH a normal invoice (sent, paid) AND
     * multiple opening invoices (paid) — CLEAN, proving the two
     * reconciliation paths (per-invoice for normal, contact-aggregate for
     * opening) stay disjoint and neither masks the other.
     */
    public function test_clean_when_a_contact_has_a_paid_normal_invoice_and_paid_opening_invoices(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $income = $this->incomeAccount($company);
        [$contact, , $bank, $invoices] = $this->setUpContactWithTwoOpeningInvoices($company);

        foreach ($invoices as $invoice) {
            $this->pay($invoice, ['amount' => $invoice->total, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-05'])->assertRedirect();
        }

        $normalInvoice = $this->domesticDraftInvoice($company, $contact);
        $this->invoiceLine($normalInvoice, $income, ['quantity' => '1', 'unit_price' => 10000, 'tax_rate_id' => null]);
        $this->sendInvoice($normalInvoice)->assertRedirect();
        $this->pay($normalInvoice->fresh(), ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-06'])->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }

    public function test_checker_reports_every_finding_simultaneously_not_just_the_first(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $receivable = $this->receivableAccount($company);

        $entry = $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        // Break balance...
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        // ...AND add a contact-less control line...
        $this->rawInsertLine($company, $entry, $receivable, debit: 500, credit: 0, contactId: null);

        // ...AND an orphaned entry.
        DB::table('journal_entries')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Orphaned entry (raw insert)',
            'source_type' => 'manual_journal',
            'source_id' => 999999,
            'entry_role' => 'primary',
            'created_by' => null,
        ]);

        $findings = app(IntegrityChecker::class)->checkCompany($company);
        $checks = collect($findings)->pluck('check')->all();

        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);
        $this->assertContains('control_contact', $checks);
        $this->assertContains('orphan_entries', $checks);
    }

    // ---------------------------------------------------------------
    // ledger:verify command — exit codes and persistence
    // ---------------------------------------------------------------

    public function test_command_exits_zero_and_persists_a_clean_run_when_the_only_company_is_clean(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $this->artisan('ledger:verify')->assertExitCode(0);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertTrue($run->clean);
        $this->assertNull($run->findings);
    }

    public function test_command_exits_nonzero_and_logs_when_there_are_zero_companies(): void
    {
        Log::spy();

        $this->assertSame(0, Company::query()->count());

        $this->artisan('ledger:verify')->assertExitCode(1);

        Log::shouldHaveReceived('error')->atLeast()->once();
        $this->assertSame(0, IntegrityRun::query()->withoutGlobalScopes()->count());
    }

    public function test_command_exits_nonzero_and_persists_findings_for_a_corrupt_company(): void
    {
        Log::spy();

        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_command_isolates_findings_to_the_tampered_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postBalancedEntry($companyA, [
            ['account_id' => $bankA->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $incomeA->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $tamperedLine = JournalLine::query()->where('account_id', $bankA->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $tamperedLine->id]);

        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postBalancedEntry($companyB, [
            ['account_id' => $bankB->id, 'debit' => 20000, 'credit' => 0],
            ['account_id' => $incomeB->id, 'debit' => 0, 'credit' => 20000],
        ]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $runA = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $companyA->id)->sole();
        $runB = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->sole();

        $this->assertFalse($runA->clean);
        $this->assertNotNull($runA->findings);

        $this->assertTrue($runB->clean);
        $this->assertNull($runB->findings);
    }

    /**
     * Vacuous-scope guard, precondition: a company that genuinely has
     * posted entries must see a non-zero count when queried through its own
     * CompanyContext. This is the invariant the guard in
     * VerifyLedgerIntegrity relies on holding true in the healthy case —
     * without it, the guard's comparison (documents exist vs. entries seen
     * in-context) would be meaningless.
     */
    public function test_a_company_with_posted_entries_yields_nonzero_entries_in_its_own_context(): void
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $count = app(CompanyContext::class)->runFor($company, fn () => JournalEntry::query()->count());

        $this->assertGreaterThan(0, $count);
    }

    /**
     * A genuine (not synthetic) trip of the vacuous-scope guard: a company
     * with a posted ManualJournal document but zero JournalEntry rows at
     * all. The ground-truth check (raw query against manual_journals) says
     * "this company has documents"; the in-context check (JournalEntry::
     * query()->count()) says "zero entries visible" — exactly the silent-
     * scope-returned-nothing failure the guard exists to catch, regardless
     * of whether the root cause is a scoping bug or a data anomaly.
     */
    public function test_vacuous_scope_guard_trips_for_a_company_with_a_document_but_no_ledger_entries(): void
    {
        Log::spy();

        $company = Company::factory()->create();

        DB::table('manual_journals')->insert([
            'company_id' => $company->id,
            'entry_date' => '2026-07-01',
            'memo' => 'Document with no matching ledger entry',
            'status' => 'posted',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ledger:verify')->assertExitCode(1);

        $run = IntegrityRun::query()->withoutGlobalScopes()->where('company_id', $company->id)->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('vacuous_scope', $checks);

        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // Settings > Health screen — gate matrix
    // ---------------------------------------------------------------

    public function test_owner_can_view_health_screen_with_no_run_yet(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun', null)
            );
    }

    public function test_owner_sees_the_latest_run_on_the_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $this->post('/settings/health/run')->assertRedirect(route('settings.health'));

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun.clean', true)
                ->where('latestRun.findings', [])
            );
    }

    public function test_accountant_can_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/settings/health')->assertOk();
    }

    public function test_admin_cannot_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/settings/health')->assertForbidden();
    }

    public function test_staff_cannot_view_health_screen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/settings/health')->assertForbidden();
    }

    public function test_staff_cannot_run_the_check(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->post('/settings/health/run')->assertForbidden();
        $this->assertSame(0, IntegrityRun::query()->withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------
    // "Run now" — synchronous check + audit log
    // ---------------------------------------------------------------

    public function test_run_now_creates_a_clean_run_and_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);

        $response = $this->post('/settings/health/run');

        $response->assertRedirect(route('settings.health'));

        $run = IntegrityRun::query()->sole();
        $this->assertTrue($run->clean);
        $this->assertNull($run->findings);

        $audit = ActivityLog::query()->where('action', 'integrity.checked')->sole();
        $this->assertSame($run->id, $audit->subject_id);
        $this->assertSame(true, $audit->after['clean']);
    }

    public function test_run_now_surfaces_findings_when_the_company_is_corrupt(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postBalancedEntry($company, [
            ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
        ]);
        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        $this->post('/settings/health/run')->assertRedirect(route('settings.health'));

        $run = IntegrityRun::query()->sole();
        $this->assertFalse($run->clean);
        $checks = collect($run->findings)->pluck('check')->all();
        $this->assertContains('entry_balance', $checks);
        $this->assertContains('trial_balance', $checks);

        $audit = ActivityLog::query()->where('action', 'integrity.checked')->sole();
        $this->assertSame(false, $audit->after['clean']);

        $this->get('/settings/health')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Health')
                ->where('latestRun.clean', false)
            );
    }
}
