<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\EntryRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 4 Task 6: voiding a received/partially-paid/paid bill the
 * accounting-correct way — reverse the payments first
 * (BillPaymentService::void, P7 reversal), then reverse the bill itself
 * (BillService::void, posting rule P9 — a mirror of P6/P6t). Mirrors
 * InvoiceVoidTest's own shape (P3-T5) — every scenario here drives the REAL
 * receive()/recordPayment() HTTP flows because void() needs a genuine
 * primary JournalEntry on the books to reverse.
 */
class BillVoidTest extends TestCase
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
    private function vendor(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ], $overrides));
    }

    private function apAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
            'is_system' => true,
        ]);
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

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** @return array{ap: Account, taxPayable: Account} */
    private function chart(Company $company): array
    {
        return [
            'ap' => $this->apAccount($company),
            'taxPayable' => $this->taxPayableAccount($company),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function draftBill(Company $company, Contact $contact, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
            'due_date' => '2026-08-01',
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor — mirrors BillReceiveTest::billLine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function billLine(Bill $bill, Account $categoryAccount, array $overrides = []): BillLine
    {
        $company = Company::query()->findOrFail($bill->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => BillLine::factory()->create(array_merge([
            'bill_id' => $bill->id,
            'company_id' => $bill->company_id,
            'category_account_id' => $categoryAccount->id,
        ], $overrides)));
    }

    private function receiveBillHttp(Bill $bill): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/receive", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function payHttp(Bill $bill, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidPaymentHttp(Bill $bill, BillPayment $payment, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/payments/{$payment->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidBillHttp(Bill $bill, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Builds a genuine RECEIVED bill (real draft -> receive flow) with one
     * taxed line: 10000 net + 18% (1800) tax = 11800 total.
     */
    private function receivedTaxedBill(Company $company, Contact $contact, Account $category): Bill
    {
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id, 'rate' => '18.0000']);

        $bill = $this->draftBill($company, $contact, ['bill_date' => '2026-07-01']);
        $this->billLine($bill, $category, ['amount' => 10000, 'tax_rate_id' => $taxRate->id]);
        $this->receiveBillHttp($bill)->assertRedirect();

        return $bill->fresh();
    }

    // ---------------------------------------------------------------
    // P9: void an unpaid Received bill — reverses P6t exactly
    // ---------------------------------------------------------------

    public function test_void_an_unpaid_received_bill_posts_p9_reversal_and_clears_ap(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $chart = $this->chart($company);
        $category = $this->categoryAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);
        $primaryEntry = JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Primary->value)->sole();

        $response = $this->voidBillHttp($bill);
        $response->assertRedirect(route('bills.show', $bill->id));

        $bill->refresh();
        $this->assertSame(BillStatus::Void, $bill->status);
        $this->assertNotNull($bill->voided_at);

        $reversal = JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();

        $this->assertSame($primaryEntry->id, $reversal->reverses_entry_id);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertCount(3, $reversal->lines);

        $apLine = $reversal->lines->firstWhere('account_id', $chart['ap']->id);
        $this->assertNotNull($apLine);
        $this->assertSame(11800, $apLine->debit);
        $this->assertSame(0, $apLine->credit);
        $this->assertSame($contact->id, $apLine->contact_id);

        $categoryLine = $reversal->lines->firstWhere('account_id', $category->id);
        $this->assertNotNull($categoryLine);
        $this->assertSame(0, $categoryLine->debit);
        $this->assertSame(10000, $categoryLine->credit);

        $taxLine = $reversal->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertNotNull($taxLine);
        $this->assertSame(0, $taxLine->debit);
        $this->assertSame(1800, $taxLine->credit);

        $this->assertSame($reversal->lines->sum('debit'), $reversal->lines->sum('credit'));

        $apBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ap']->id)
            ->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $apBalance);

        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'bill.voided')->sole();
        $this->assertSame($bill->id, $audit->subject_id);
        $this->assertSame($reversal->id, $audit->after['reversal_entry_id']);
    }

    // ---------------------------------------------------------------
    // Refused while a live payment exists — message names the count
    // ---------------------------------------------------------------

    public function test_void_a_bill_with_one_posted_payment_is_refused_naming_the_payment_and_changes_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);
        $this->payHttp($bill, ['amount' => 5000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $bill = $bill->fresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);

        $response = $this->voidBillHttp($bill);

        $response->assertSessionHasErrors('status');
        $message = session('errors')->first('status');
        $this->assertStringContainsString('payment', $message);
        $this->assertStringContainsString('1', $message);

        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertNull($bill->voided_at);

        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Void a fully-paid bill: void its payment first, then void succeeds
    // ---------------------------------------------------------------

    public function test_voiding_a_paid_bill_requires_voiding_its_payment_first_then_succeeds(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $chart = $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);
        $this->payHttp($bill, ['amount' => 11800, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $bill = $bill->fresh();
        $this->assertSame(BillStatus::Paid, $bill->status);

        // Direct void is refused while the payment is still live.
        $this->voidBillHttp($bill)->assertSessionHasErrors('status');

        $payment = BillPayment::query()->sole();
        $this->voidPaymentHttp($bill, $payment)->assertRedirect();

        $bill = $bill->fresh();
        $this->assertSame(BillStatus::Received, $bill->status);

        $this->voidBillHttp($bill)->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::Void, $bill->status);

        $apBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ap']->id)->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $apBalance);

        $bankBalance = DB::table('journal_lines')
            ->where('account_id', $bank->ledger_account_id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $bankBalance);

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // is_opening: friendly domain error, not a 404 (Task 6 guard for Task 7)
    // ---------------------------------------------------------------

    public function test_voiding_an_is_opening_bill_returns_a_friendly_opening_balances_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);

        // is_opening bills aren't created by any flow yet (Task 7) — forge
        // one directly, mirroring how the brief instructs this guard to be
        // proven ahead of that task landing.
        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Received->value,
            'due_date' => '2026-08-01',
            'bill_date' => '2026-07-01',
            'total' => 50000,
            'balance' => 50000,
            'is_opening' => true,
            'received_at' => now(),
        ]);

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
    // Draft rejected
    // ---------------------------------------------------------------

    public function test_voiding_a_draft_bill_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $contact);
        $this->billLine($bill, $category, ['amount' => 10000, 'tax_rate_id' => null]);

        $response = $this->voidBillHttp($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
        $this->assertSame(BillStatus::Draft, $bill->fresh()->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Second void rejected with a friendly 422
    // ---------------------------------------------------------------

    public function test_second_void_of_the_same_bill_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);

        $this->voidBillHttp($bill)->assertRedirect();

        $response = $this->voidBillHttp($bill->fresh());

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been voided', session('errors')->first('status'));

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_void_posts_exactly_one_reversal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $key = (string) Str::uuid();

        $bill = $this->receivedTaxedBill($company, $contact, $category);

        $first = $this->voidBillHttp($bill, $key);
        $second = $this->voidBillHttp($bill, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'bill.voided')->count());
        $this->assertLedgerBalances();
    }

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing_on_void(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);

        $response = $this->postJson("/bills/{$bill->id}/void");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(BillStatus::Received, $bill->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Locked period: reversal (dated today) 422s when today is locked
    // ---------------------------------------------------------------

    public function test_voiding_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);

        $bill = $this->receivedTaxedBill($company, $contact, $category);

        $company->forceFill(['books_locked_through' => CarbonImmutable::today()->toDateString()])->save();
        // See ExpenseVoidTest/InvoiceVoidTest's identical locked-period tests
        // for why this cache-bust is required.
        $user->unsetRelation('currentCompany');

        $response = $this->voidBillHttp($bill);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('locked', session('errors')->first('status'));

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'bill')->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: bills.void — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_void_a_bill(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->vendor($company);
            $this->chart($company);
            $category = $this->categoryAccount($company);

            $bill = $this->receivedTaxedBill($company, $contact, $category);

            $this->voidBillHttp($bill->fresh())->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_voiding_a_bill(): void
    {
        $company = Company::factory()->create();
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);

        $owner = User::factory()->create();
        $owner->companies()->attach($company, ['role' => Role::Owner->value]);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner);

        $bill = $this->receivedTaxedBill($company, $contact, $category);

        $this->actingAsRole($company, Role::Staff);

        $this->voidBillHttp($bill)->assertForbidden();
        $this->assertSame(BillStatus::Received, $bill->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_bill_404s_on_void(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->vendor($companyA);
        $this->chart($companyA);
        $categoryA = $this->categoryAccount($companyA);

        $ownerA = User::factory()->create();
        $ownerA->companies()->attach($companyA, ['role' => Role::Owner->value]);
        $ownerA->forceFill(['current_company_id' => $companyA->id])->save();
        $this->actingAs($ownerA);
        $foreignBill = $this->receivedTaxedBill($companyA, $contactA, $categoryA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->voidBillHttp($foreignBill)->assertNotFound();
    }
}
