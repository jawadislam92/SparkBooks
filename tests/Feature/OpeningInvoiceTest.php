<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\OpeningDocument;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 3 Task 6 (whole-branch carry-in): Phase 2's OpeningBalanceService
 * stored a customer opening receivable as a bare opening_documents row, with
 * the AR already posted (P8b) but nothing a payment could ever apply
 * against. This task makes them payable by representing each one as an
 * is_opening Invoice — created straight to Sent, balance = the opening
 * amount, but WITHOUT posting a second AR entry (the P8b entry already did)
 * — so InvoicePaymentService::recordPayment (Task 4, unmodified) settles it
 * through the exact same P2/P3 path as any ordinary invoice.
 */
class OpeningInvoiceTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function account(Company $company, array $overrides = []): Account
    {
        return app(CompanyContext::class)->runFor($company, fn () => Account::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function arAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '1100', 'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Receivable->value, 'is_system' => true,
        ]);
    }

    private function apAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '2000', 'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Payable->value, 'is_system' => true,
        ]);
    }

    private function obeAccount(Company $company): Account
    {
        return $this->account($company, [
            'code' => '3100', 'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity->value, 'subtype' => AccountSubtype::Equity->value, 'is_system' => true,
        ]);
    }

    /** @return array{ar: Account, ap: Account, obe: Account} */
    private function chart(Company $company): array
    {
        return [
            'ar' => $this->arAccount($company),
            'ap' => $this->apAccount($company),
            'obe' => $this->obeAccount($company),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function contact(Company $company, array $overrides = []): Contact
    {
        return app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function customer(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Customer->value], $overrides));
    }

    private function vendor(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Vendor->value], $overrides));
    }

    /** @param array<string, mixed> $data */
    private function postOpeningBalances(array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/setup/opening-balances', array_merge([
            'books_start_date' => '2026-07-01',
            'bank_lines' => [],
            'customer_lines' => [],
            'vendor_lines' => [],
        ], $data), ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $data */
    private function pay(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** Net (debit - credit) balance for one account, optionally scoped to one contact. */
    private function ledgerNet(Account $account, ?int $contactId = null): int
    {
        $q = DB::table('journal_lines')->where('account_id', $account->id);
        if ($contactId !== null) {
            $q->where('contact_id', $contactId);
        }

        return (int) $q->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')->value('net');
    }

    // ---------------------------------------------------------------
    // Going forward: OpeningBalanceService creates an is_opening invoice
    // per customer opening receivable
    // ---------------------------------------------------------------

    public function test_customer_opening_receivable_creates_a_payable_is_opening_invoice_with_no_second_ar_posting(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ar' => $ar] = $this->chart($company);
        $customerA = $this->customer($company, ['name' => 'Acme Traders']);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'reference' => 'INV-OLD-1', 'original_date' => '2026-05-01', 'due_date' => '2026-06-15', 'amount' => 200000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $this->assertNotNull($doc->invoice_id, 'The receivable opening_document must be linked to an is_opening invoice.');

        $invoice = Invoice::query()->findOrFail($doc->invoice_id);
        $this->assertTrue($invoice->is_opening);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertStringStartsWith('OB-', $invoice->number);
        $this->assertSame($customerA->id, $invoice->contact_id);
        $this->assertSame(200000, $invoice->total);
        $this->assertSame(200000, $invoice->balance);
        $this->assertSame(0, $invoice->amount_paid);

        // Exactly one JournalEntry exists (the P8b opening entry) — the
        // is_opening invoice must NOT post a second one of its own.
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());

        // The AR for this contact equals the opening amount exactly — not
        // doubled by a phantom second posting from invoice creation.
        $this->assertSame(200000, $this->ledgerNet($ar, $customerA->id));

        $this->assertLedgerBalances();
    }

    public function test_is_opening_invoice_due_date_carries_from_the_opening_document(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $customerA = $this->customer($company);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-20', 'amount' => 50000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $invoice = Invoice::query()->findOrFail($doc->invoice_id);

        $this->assertSame('2026-06-20', $invoice->due_date->toDateString());
        $this->assertSame('2026-05-01', $invoice->issue_date->toDateString());
    }

    public function test_vendor_opening_payable_still_only_creates_an_opening_document_no_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 60000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $this->assertNull($doc->invoice_id);
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Payment on an is_opening invoice: exact same P2/P3 path, no code
    // change needed in InvoicePaymentService
    // ---------------------------------------------------------------

    public function test_recording_a_payment_on_an_is_opening_invoice_posts_p2_and_clears_the_opening_ar(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ar' => $ar] = $this->chart($company);
        $customerA = $this->customer($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 150000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $invoice = Invoice::query()->findOrFail($doc->invoice_id);

        $response = $this->pay($invoice, [
            'amount' => 150000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);
        $response->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(150000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->balance);

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $this->assertCount(2, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(150000, $bankLine->debit);

        $arLine = $entry->lines->firstWhere('account_id', $ar->id);
        $this->assertNotNull($arLine);
        $this->assertSame(150000, $arLine->credit);
        $this->assertSame($customerA->id, $arLine->contact_id);

        // Opening AR (Dr 150000) + payment (Cr 150000) nets to zero for this contact.
        $this->assertSame(0, $this->ledgerNet($ar, $customerA->id));

        // No income posting anywhere — only the opening entry + the payment entry exist.
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $this->assertLedgerBalances();
    }

    public function test_partial_payment_on_an_is_opening_invoice_marks_it_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $customerA = $this->customer($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 100000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $invoice = Invoice::query()->findOrFail($doc->invoice_id);

        $this->pay($invoice, ['amount' => 40000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(40000, $invoice->amount_paid);
        $this->assertSame(60000, $invoice->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Re-run: prior run's is_opening invoices are voided; new run's are fresh
    // ---------------------------------------------------------------

    public function test_rerun_voids_the_prior_runs_is_opening_invoices_and_the_new_run_creates_fresh_ones(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ar' => $ar] = $this->chart($company);
        $customerA = $this->customer($company);
        $customerB = $this->customer($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
        ])->assertRedirect();

        $doc1 = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $invoice1 = Invoice::query()->findOrFail($doc1->invoice_id);
        $this->assertSame(InvoiceStatus::Sent, $invoice1->status);

        $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'customer_lines' => [
                ['contact_id' => $customerB->id, 'original_date' => '2026-07-01', 'due_date' => '2026-08-01', 'amount' => 90000],
            ],
        ])->assertRedirect();

        // The old run's invoice is voided — it's no longer payable.
        $invoice1->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice1->status);
        $this->assertNotNull($invoice1->voided_at);

        // A fresh is_opening invoice exists for the new run.
        $run2 = OpeningBalanceRun::query()->where('status', 'active')->sole();
        $doc2 = OpeningDocument::query()->where('opening_balance_run_id', $run2->id)->sole();
        $invoice2 = Invoice::query()->findOrFail($doc2->invoice_id);
        $this->assertSame(InvoiceStatus::Sent, $invoice2->status);
        $this->assertSame(90000, $invoice2->balance);
        $this->assertNotSame($invoice1->id, $invoice2->id);

        // The voided invoice must not be payable any more.
        $this->pay($invoice1, ['amount' => 1, 'bank_account_id' => $this->bankAccount($company)->id, 'payment_date' => '2026-08-01'])
            ->assertSessionHasErrors('status');

        // AR nets to only the new run's amount: run1's 200000 was fully
        // reversed by voidPriorRun()'s ledger reversal, leaving only run2's 90000.
        $this->assertSame(0, $this->ledgerNet($ar, $customerA->id));
        $this->assertSame(90000, $this->ledgerNet($ar, $customerB->id));

        $this->assertLedgerBalances();
    }

    public function test_rerun_with_no_customer_lines_still_voids_the_prior_runs_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $customerA = $this->customer($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 70000],
            ],
        ])->assertRedirect();

        $doc1 = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $invoice1 = Invoice::query()->findOrFail($doc1->invoice_id);

        // Re-run with only a bank line — no customer/vendor lines at all.
        $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ])->assertRedirect();

        $invoice1->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice1->status);
        $this->assertSame(0, Invoice::query()->where('status', '!=', InvoiceStatus::Void->value)->count(), 'No live is_opening invoice should remain — the re-run had no customer lines.');
    }

    // ---------------------------------------------------------------
    // Backfill command: sparkbooks:reconcile-opening-invoices
    // ---------------------------------------------------------------

    /**
     * Simulates pre-Task-6 data: an opening_document created directly
     * (bypassing OpeningBalanceService, which now always links an invoice),
     * exactly what existing dev/SparkingAsia data looks like.
     */
    private function legacyOpeningDocument(Company $company, OpeningBalanceRun $run, Contact $contact, array $overrides = []): OpeningDocument
    {
        return app(CompanyContext::class)->runFor($company, fn () => OpeningDocument::query()->create(array_merge([
            'opening_balance_run_id' => $run->id,
            'contact_id' => $contact->id,
            'invoice_id' => null,
            'kind' => 'receivable',
            'reference' => 'LEGACY-1',
            'original_date' => '2026-04-01',
            'due_date' => '2026-05-01',
            'amount' => 45000,
            'status' => 'open',
        ], $overrides)));
    }

    private function legacyRun(Company $company): OpeningBalanceRun
    {
        return app(CompanyContext::class)->runFor($company, fn () => OpeningBalanceRun::query()->create([
            'books_start_date' => '2026-04-01',
            'status' => 'active',
        ]));
    }

    public function test_backfill_command_creates_an_is_opening_invoice_for_a_legacy_unlinked_receivable(): void
    {
        $company = Company::factory()->create();
        // No HTTP request happens in this test (only artisan calls), so
        // CompanyContext is never set by SetCompanyContext middleware —
        // set it explicitly, persisting for the rest of the test, mirroring
        // what a real request would leave behind.
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $customerA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ]));
        $run = $this->legacyRun($company);
        $doc = $this->legacyOpeningDocument($company, $run, $customerA);

        $this->artisan('sparkbooks:reconcile-opening-invoices')->assertSuccessful();

        $doc->refresh();
        $this->assertNotNull($doc->invoice_id);

        $invoice = app(CompanyContext::class)->runFor($company, fn () => Invoice::query()->findOrFail($doc->invoice_id));
        $this->assertTrue($invoice->is_opening);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertStringStartsWith('OB-', $invoice->number);
        $this->assertSame(45000, $invoice->balance);
        $this->assertSame(45000, $invoice->total);
        $this->assertSame($customerA->id, $invoice->contact_id);
        $this->assertSame('2026-05-01', $invoice->due_date->toDateString());

        // The command must NEVER post a ledger entry — the AR was already
        // posted by the original (legacy) opening run.
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_backfill_command_is_idempotent_on_a_second_run(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $customerA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ]));
        $run = $this->legacyRun($company);
        $this->legacyOpeningDocument($company, $run, $customerA);

        $this->artisan('sparkbooks:reconcile-opening-invoices')->assertSuccessful();
        $firstCount = app(CompanyContext::class)->runFor($company, fn () => Invoice::query()->count());

        $this->artisan('sparkbooks:reconcile-opening-invoices')->assertSuccessful();
        $secondCount = app(CompanyContext::class)->runFor($company, fn () => Invoice::query()->count());

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount, 'A second run must not create a duplicate invoice for an already-linked opening_document.');
    }

    public function test_backfill_command_ignores_voided_and_payable_opening_documents(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $customerA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ]));
        $vendorA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ]));
        $run = $this->legacyRun($company);
        $voidedDoc = $this->legacyOpeningDocument($company, $run, $customerA, ['status' => 'void', 'reference' => 'VOIDED-1']);
        $payableDoc = $this->legacyOpeningDocument($company, $run, $vendorA, ['kind' => 'payable', 'reference' => 'PAYABLE-1']);

        $this->artisan('sparkbooks:reconcile-opening-invoices')->assertSuccessful();

        $this->assertNull($voidedDoc->fresh()->invoice_id, 'A voided opening_document must not be reconciled into a payable invoice.');
        $this->assertNull($payableDoc->fresh()->invoice_id, 'A payable opening_document is out of scope for this task — vendors stay opening_documents-only.');
        $this->assertSame(0, app(CompanyContext::class)->runFor($company, fn () => Invoice::query()->count()));
    }

    // ---------------------------------------------------------------
    // OpeningBalancesTest (Phase 2) stays green — sanity check duplicated
    // here for this task's own regression coverage.
    // ---------------------------------------------------------------

    public function test_opening_document_amount_and_ledger_entry_count_are_unchanged_by_the_invoice_addition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $customerA = $this->customer($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 500000]],
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-15', 'due_date' => '2026-06-15', 'amount' => 80000],
            ],
        ])->assertRedirect();

        // Still exactly ONE ledger entry for the whole opening posting (P8a-c
        // + OBE plug all land in one entry, unchanged by this task).
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());
        $entry = JournalEntry::query()->where('source_type', 'opening_balance_run')->sole();
        $this->assertCount(4, $entry->lines);

        $this->assertLedgerBalances();
    }
}
