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
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 4 Task 6: voiding a bill payment — posting rule P9 (spec §6), a
 * mirror reversal of the payment's own P7 entry, via BillPaymentService::void
 * — the AP-side sibling of InvoicePaymentService::voidPayment (P3-T5).
 * Restores the bill's amount_paid/balance/status (Paid -> PartiallyPaid ->
 * Received as the balance reopens), never automatically triggered by voiding
 * the bill itself (that's a separate, conscious action — BillVoidTest).
 */
class BillPaymentVoidTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @return array{ap: Account} */
    private function chart(Company $company): array
    {
        return ['ap' => $this->apAccount($company)];
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
     * untaxed line totalling $total, via BillService::receive.
     */
    private function receivedBill(Company $company, Contact $contact, Account $category, int $total): Bill
    {
        $draft = $this->draftBill($company, $contact);
        $this->billLine($draft, $category, ['amount' => $total, 'tax_rate_id' => null]);
        $this->receiveBillHttp($draft)->assertRedirect();

        return $draft->fresh();
    }

    // ---------------------------------------------------------------
    // voidPayment: reverses P7, restores AP with contact
    // ---------------------------------------------------------------

    public function test_void_payment_reverses_p7_exactly_and_returns_the_bill_to_received(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $chart = $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, $category, 118000);
        $this->payHttp($bill, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $bill = $bill->fresh();
        $this->assertSame(BillStatus::Paid, $bill->status);

        $payment = BillPayment::query()->sole();
        $primaryPaymentEntry = JournalEntry::query()
            ->where('source_type', 'bill_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Primary->value)->sole();

        $response = $this->voidPaymentHttp($bill, $payment);
        $response->assertRedirect(route('bills.show', $bill->id));

        $payment->refresh();
        $this->assertSame('void', $payment->status);

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(118000, $bill->balance);

        $reversal = JournalEntry::query()
            ->where('source_type', 'bill_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();
        $this->assertSame($primaryPaymentEntry->id, $reversal->reverses_entry_id);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertCount(2, $reversal->lines);

        // Mirror of P7's Dr AP / Cr bank: the reversal Cr's AP (WITH the
        // vendor's contact_id) and Dr's the bank account.
        $apLine = $reversal->lines->firstWhere('account_id', $chart['ap']->id);
        $this->assertSame(0, $apLine->debit);
        $this->assertSame(118000, $apLine->credit);
        $this->assertSame($contact->id, $apLine->contact_id);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(118000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);

        $this->assertSame($reversal->lines->sum('debit'), $reversal->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'bill_payment.voided')->sole();
        $this->assertSame($payment->id, $audit->subject_id);
        $this->assertSame($bill->id, $audit->after['bill_id']);
        $this->assertSame($reversal->id, $audit->after['reversal_entry_id']);
    }

    // ---------------------------------------------------------------
    // Paid -> PartiallyPaid -> Received transitions through sequential voids
    // ---------------------------------------------------------------

    public function test_voiding_one_of_two_payments_leaves_the_bill_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, $category, 100000);
        $this->payHttp($bill, ['amount' => 60000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $this->payHttp($bill, ['amount' => 40000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-11'])->assertRedirect();

        $bill = $bill->fresh();
        $this->assertSame(BillStatus::Paid, $bill->status);

        $payments = BillPayment::query()->orderBy('id')->get();
        $second = $payments->last();

        $this->voidPaymentHttp($bill, $second)->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(60000, $bill->amount_paid);
        $this->assertSame(40000, $bill->balance);
        $this->assertLedgerBalances();

        // Now void the remaining payment too — bill drops fully back to Received.
        $first = $payments->first();
        $this->voidPaymentHttp($bill, $first)->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(100000, $bill->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Second void rejected with a friendly 422
    // ---------------------------------------------------------------

    public function test_second_void_payment_of_the_same_payment_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, $category, 50000);
        $this->payHttp($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $payment = BillPayment::query()->sole();

        $this->voidPaymentHttp($bill, $payment)->assertRedirect();

        $response = $this->voidPaymentHttp($bill, $payment->fresh());

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been reversed', session('errors')->first('status'));

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'bill_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_void_payment_posts_exactly_one_reversal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $bill = $this->receivedBill($company, $contact, $category, 50000);
        $this->payHttp($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $payment = BillPayment::query()->sole();

        $first = $this->voidPaymentHttp($bill, $payment, $key);
        $second = $this->voidPaymentHttp($bill, $payment, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'bill_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'bill_payment.voided')->count());
        $this->assertLedgerBalances();
    }

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing_on_void_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, $category, 50000);
        $this->payHttp($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $payment = BillPayment::query()->sole();

        $response = $this->postJson("/bills/{$bill->id}/payments/{$payment->id}/void");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame('posted', $payment->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Gate: bills.void — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_void_a_bill_payment(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->vendor($company);
            $this->chart($company);
            $category = $this->categoryAccount($company);
            $bank = $this->bankAccount($company);

            $bill = $this->receivedBill($company, $contact, $category, 50000);
            $this->payHttp($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
            $payment = BillPayment::query()->where('bill_id', $bill->id)->sole();

            $this->voidPaymentHttp($bill, $payment)->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_voiding_a_bill_payment(): void
    {
        $company = Company::factory()->create();
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $owner = User::factory()->create();
        $owner->companies()->attach($company, ['role' => Role::Owner->value]);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner);

        $bill = $this->receivedBill($company, $contact, $category, 50000);
        $this->payHttp($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $payment = BillPayment::query()->sole();

        $this->actingAsRole($company, Role::Staff);

        $this->voidPaymentHttp($bill, $payment)->assertForbidden();
        $this->assertSame('posted', $payment->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Scoped nested 404: payment of another bill/company
    // ---------------------------------------------------------------

    public function test_a_payment_belonging_to_a_different_bill_404s(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company);

        $billA = $this->receivedBill($company, $contact, $category, 50000);
        $this->payHttp($billA, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $paymentOfA = BillPayment::query()->sole();

        $billB = $this->receivedBill($company, $contact, $category, 30000);

        // paymentOfA genuinely belongs to billA, not billB — the nested
        // route must 404, not silently operate on the wrong bill's payment.
        $this->voidPaymentHttp($billB, $paymentOfA)->assertNotFound();
        $this->assertSame('posted', $paymentOfA->fresh()->status);
    }

    public function test_a_foreign_companys_payment_404s(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->vendor($companyA);
        $this->chart($companyA);
        $categoryA = $this->categoryAccount($companyA);
        $bankA = $this->bankAccount($companyA);

        $ownerA = User::factory()->create();
        $ownerA->companies()->attach($companyA, ['role' => Role::Owner->value]);
        $ownerA->forceFill(['current_company_id' => $companyA->id])->save();
        $this->actingAs($ownerA);

        $foreignBill = $this->receivedBill($companyA, $contactA, $categoryA, 50000);
        $this->payHttp($foreignBill, ['amount' => 50000, 'bank_account_id' => $bankA->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $foreignPayment = BillPayment::query()->sole();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $contactB = $this->vendor($companyB);
        $this->chart($companyB);
        $categoryB = $this->categoryAccount($companyB);
        // A bill that DOES belong to company B, so the 404 is specifically
        // about the payment not belonging to it.
        $billB = $this->receivedBill($companyB, $contactB, $categoryB, 30000);

        $this->voidPaymentHttp($billB, $foreignPayment)->assertNotFound();
    }
}
