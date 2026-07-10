<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\EntryRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\OpeningDocument;
use App\Models\User;
use App\Services\IntegrityChecker;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 4 Task 7 — the exact AP-side mirror of OpeningInvoiceTest (Phase 3
 * Task 6): OpeningBalanceService stored a vendor opening payable as a bare
 * opening_documents row, with the AP already posted (P8c) but nothing a
 * payment could ever apply against. This task makes them payable by
 * representing each one as an is_opening Bill — created straight to
 * Received, balance = the opening amount, but WITHOUT posting a second AP
 * entry (the P8c entry already did) — so BillPaymentService::record (Task 5,
 * unmodified) settles it through the exact same P7 path as any ordinary
 * bill.
 */
class OpeningBillsTest extends TestCase
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
    private function pay(Bill $bill, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidBillHttp(Bill $bill, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/void", [], [
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
    // Going forward: OpeningBalanceService creates an is_opening bill
    // per vendor opening payable
    // ---------------------------------------------------------------

    public function test_vendor_opening_payable_creates_a_payable_is_opening_bill_with_no_second_ap_posting(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ap' => $ap] = $this->chart($company);
        $vendorA = $this->vendor($company, ['name' => 'Beta Supplies']);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'reference' => 'BILL-OLD-1', 'original_date' => '2026-05-01', 'due_date' => '2026-06-15', 'amount' => 80000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $this->assertNotNull($doc->bill_id, 'The payable opening_document must be linked to an is_opening bill.');

        $bill = Bill::query()->findOrFail($doc->bill_id);
        $this->assertTrue($bill->is_opening);
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame($vendorA->id, $bill->contact_id);
        $this->assertSame(80000, $bill->total);
        $this->assertSame(80000, $bill->balance);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(80000, $bill->subtotal);
        $this->assertSame(0, $bill->tax_total);

        // Exactly one JournalEntry exists (the P8c opening entry) — the
        // is_opening bill must NOT post a second one of its own.
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance_run')->count());

        // The AP for this contact equals the opening amount exactly — not
        // doubled by a phantom second posting from bill creation.
        $this->assertSame(80000, $this->ledgerNet($ap, $vendorA->id) * -1);

        $this->assertLedgerBalances();
    }

    public function test_is_opening_bill_dates_and_reference_carry_from_the_opening_document(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'reference' => 'BILL-OLD-2', 'original_date' => '2026-05-01', 'due_date' => '2026-06-20', 'amount' => 50000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill = Bill::query()->findOrFail($doc->bill_id);

        $this->assertSame('2026-06-20', $bill->due_date->toDateString());
        $this->assertSame('2026-05-01', $bill->bill_date->toDateString());
        $this->assertSame('BILL-OLD-2', $bill->reference);
    }

    public function test_customer_opening_receivable_still_only_creates_an_opening_document_and_invoice_no_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $customerA = $this->customer($company);

        $this->postOpeningBalances([
            'customer_lines' => [
                ['contact_id' => $customerA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 60000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'receivable')->sole();
        $this->assertNull($doc->bill_id);
        $this->assertSame(0, Bill::query()->count());
    }

    // ---------------------------------------------------------------
    // Payment on an is_opening bill: exact same P7 path, no code change
    // needed in BillPaymentService
    // ---------------------------------------------------------------

    public function test_recording_a_payment_on_an_is_opening_bill_posts_p7_and_clears_the_opening_ap(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ap' => $ap] = $this->chart($company);
        $vendorA = $this->vendor($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 150000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill = Bill::query()->findOrFail($doc->bill_id);

        $response = $this->pay($bill, [
            'amount' => 150000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);
        $response->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame(150000, $bill->amount_paid);
        $this->assertSame(0, $bill->balance);

        $entry = JournalEntry::query()->where('source_type', 'bill_payment')->sole();
        $this->assertCount(2, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(150000, $bankLine->credit);

        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertNotNull($apLine);
        $this->assertSame(150000, $apLine->debit);
        $this->assertSame($vendorA->id, $apLine->contact_id);

        // Opening AP (Cr 150000) + payment (Dr 150000) nets to zero for this contact.
        $this->assertSame(0, $this->ledgerNet($ap, $vendorA->id));

        // No expense/category posting anywhere — only the opening entry + the payment entry exist.
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());

        $this->assertLedgerBalances();
    }

    public function test_partial_payment_on_an_is_opening_bill_marks_it_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $vendorA = $this->vendor($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 100000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill = Bill::query()->findOrFail($doc->bill_id);

        $this->pay($bill, ['amount' => 40000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(40000, $bill->amount_paid);
        $this->assertSame(60000, $bill->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Re-run: prior run's is_opening bills are voided; new run's are fresh
    // ---------------------------------------------------------------

    public function test_rerun_voids_the_prior_runs_is_opening_bills_and_the_new_run_creates_fresh_ones(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        ['ap' => $ap] = $this->chart($company);
        $vendorA = $this->vendor($company);
        $vendorB = $this->vendor($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 200000],
            ],
        ])->assertRedirect();

        $doc1 = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill1 = Bill::query()->findOrFail($doc1->bill_id);
        $this->assertSame(BillStatus::Received, $bill1->status);

        $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'vendor_lines' => [
                ['contact_id' => $vendorB->id, 'original_date' => '2026-07-01', 'due_date' => '2026-08-01', 'amount' => 90000],
            ],
        ])->assertRedirect();

        // The old run's bill is voided — it's no longer payable.
        $bill1->refresh();
        $this->assertSame(BillStatus::Void, $bill1->status);
        $this->assertNotNull($bill1->voided_at);

        // A fresh is_opening bill exists for the new run.
        $run2 = OpeningBalanceRun::query()->where('status', 'active')->sole();
        $doc2 = OpeningDocument::query()->where('opening_balance_run_id', $run2->id)->sole();
        $bill2 = Bill::query()->findOrFail($doc2->bill_id);
        $this->assertSame(BillStatus::Received, $bill2->status);
        $this->assertSame(90000, $bill2->balance);
        $this->assertNotSame($bill1->id, $bill2->id);

        // The voided bill must not be payable any more — BillPaymentService::
        // guard() checks status->canReceivePayment(), which Void fails.
        $this->pay($bill1, ['amount' => 1, 'bank_account_id' => $this->bankAccount($company)->id, 'payment_date' => '2026-08-01'])
            ->assertSessionHasErrors('status');

        // AP nets to only the new run's amount: run1's 200000 was fully
        // reversed by voidPriorRun()'s ledger reversal, leaving only run2's 90000.
        $this->assertSame(0, $this->ledgerNet($ap, $vendorA->id));
        $this->assertSame(-90000, $this->ledgerNet($ap, $vendorB->id));

        $this->assertLedgerBalances();
    }

    public function test_rerun_with_no_vendor_lines_still_voids_the_prior_runs_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'books_start_date' => '2026-07-01',
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 70000],
            ],
        ])->assertRedirect();

        $doc1 = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill1 = Bill::query()->findOrFail($doc1->bill_id);

        // Re-run with only a bank line — no customer/vendor lines at all.
        $this->postOpeningBalances([
            'books_start_date' => '2026-08-01',
            'bank_lines' => [['bank_account_id' => $bank->id, 'amount' => 10000]],
        ])->assertRedirect();

        $bill1->refresh();
        $this->assertSame(BillStatus::Void, $bill1->status);
        $this->assertSame(0, Bill::query()->where('status', '!=', BillStatus::Void->value)->count(), 'No live is_opening bill should remain — the re-run had no vendor lines.');
    }

    // ---------------------------------------------------------------
    // Void guard regression: a REAL opening bill (not factory-forged) is
    // refused with the friendly Task 6 error
    // ---------------------------------------------------------------

    public function test_voiding_a_real_is_opening_bill_returns_a_friendly_opening_balances_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $vendorA = $this->vendor($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 50000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill = Bill::query()->findOrFail($doc->bill_id);

        $response = $this->voidBillHttp($bill);

        $response->assertSessionHasErrors('status');
        $message = session('errors')->first('status');
        $this->assertStringContainsString('Opening Balances', $message);

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertNull($bill->voided_at);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Backfill command: sparkbooks:reconcile-opening-bills
    // ---------------------------------------------------------------

    /**
     * Simulates pre-Task-7 data: an opening_document created directly
     * (bypassing OpeningBalanceService, which now always links a bill),
     * exactly what existing dev data looks like.
     */
    private function legacyOpeningDocument(Company $company, OpeningBalanceRun $run, Contact $contact, array $overrides = []): OpeningDocument
    {
        return app(CompanyContext::class)->runFor($company, fn () => OpeningDocument::query()->create(array_merge([
            'opening_balance_run_id' => $run->id,
            'contact_id' => $contact->id,
            'bill_id' => null,
            'kind' => 'payable',
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

    public function test_backfill_command_creates_an_is_opening_bill_for_a_legacy_unlinked_payable(): void
    {
        $company = Company::factory()->create();
        // No HTTP request happens in this test (only artisan calls), so
        // CompanyContext is never set by SetCompanyContext middleware —
        // set it explicitly, persisting for the rest of the test, mirroring
        // what a real request would leave behind.
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $vendorA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ]));
        $run = $this->legacyRun($company);
        $doc = $this->legacyOpeningDocument($company, $run, $vendorA);

        $this->artisan('sparkbooks:reconcile-opening-bills')->assertSuccessful();

        $doc->refresh();
        $this->assertNotNull($doc->bill_id);

        $bill = app(CompanyContext::class)->runFor($company, fn () => Bill::query()->findOrFail($doc->bill_id));
        $this->assertTrue($bill->is_opening);
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(45000, $bill->balance);
        $this->assertSame(45000, $bill->total);
        $this->assertSame($vendorA->id, $bill->contact_id);
        $this->assertSame('2026-05-01', $bill->due_date->toDateString());
        $this->assertSame('LEGACY-1', $bill->reference);

        // The command must NEVER post a ledger entry — the AP was already
        // posted by the original (legacy) opening run.
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_backfill_command_is_idempotent_on_a_second_run(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $vendorA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ]));
        $run = $this->legacyRun($company);
        $this->legacyOpeningDocument($company, $run, $vendorA);

        $this->artisan('sparkbooks:reconcile-opening-bills')->assertSuccessful();
        $firstCount = app(CompanyContext::class)->runFor($company, fn () => Bill::query()->count());

        $this->artisan('sparkbooks:reconcile-opening-bills')->assertSuccessful();
        $secondCount = app(CompanyContext::class)->runFor($company, fn () => Bill::query()->count());

        $this->assertSame(1, $firstCount);
        $this->assertSame(1, $secondCount, 'A second run must not create a duplicate bill for an already-linked opening_document.');
    }

    public function test_backfill_command_ignores_voided_and_receivable_opening_documents(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->chart($company);
        $vendorA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ]));
        $customerA = app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ]));
        $run = $this->legacyRun($company);
        $voidedDoc = $this->legacyOpeningDocument($company, $run, $vendorA, ['status' => 'void', 'reference' => 'VOIDED-1']);
        $receivableDoc = $this->legacyOpeningDocument($company, $run, $customerA, ['kind' => 'receivable', 'reference' => 'RECEIVABLE-1']);

        $this->artisan('sparkbooks:reconcile-opening-bills')->assertSuccessful();

        $this->assertNull($voidedDoc->fresh()->bill_id, 'A voided opening_document must not be reconciled into a payable bill.');
        $this->assertNull($receivableDoc->fresh()->bill_id, 'A receivable opening_document is out of scope for this task — customers stay opening_documents/invoice-only.');
        $this->assertSame(0, app(CompanyContext::class)->runFor($company, fn () => Bill::query()->count()));
    }

    // ---------------------------------------------------------------
    // OpeningBalancesTest (Phase 2) stays green — sanity check duplicated
    // here for this task's own regression coverage.
    // ---------------------------------------------------------------

    public function test_opening_document_amount_and_ledger_entry_count_are_unchanged_by_the_bill_addition(): void
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

    // ---------------------------------------------------------------
    // Checker: an opening bill on the books must not trip IntegrityChecker
    // ---------------------------------------------------------------

    public function test_integrity_checker_stays_clean_with_an_opening_bill_present(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->chart($company);
        $vendorA = $this->vendor($company);
        $bank = $this->bankAccount($company);

        $this->postOpeningBalances([
            'vendor_lines' => [
                ['contact_id' => $vendorA->id, 'original_date' => '2026-05-01', 'due_date' => '2026-06-01', 'amount' => 70000],
            ],
        ])->assertRedirect();

        $doc = OpeningDocument::query()->where('kind', 'payable')->sole();
        $bill = Bill::query()->findOrFail($doc->bill_id);

        // Partially pay it too, so both an untouched-and-a-touched opening
        // bill are represented in the same round trip.
        $this->pay($bill, ['amount' => 20000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        $findings = app(IntegrityChecker::class)->checkCompany($company);

        $this->assertSame([], $findings);
    }
}
