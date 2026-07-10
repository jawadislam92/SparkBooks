<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 Task 9: the Bills/Show detail page's data contract — the
 * `bill`/`bankAccounts` Inertia props BillController::show renders, plus the
 * shared `can` map entries (bills.receive/payments.record/bills.void)
 * Show.tsx's action bar gates on. Mirrors InvoiceShowTest/ExpenseShowTest's
 * own shape/reasoning exactly — hand-rolled fixtures, since this only tests
 * what the CONTROLLER SERIALIZES.
 */
class BillShowTest extends TestCase
{
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

    /**
     * A draft bill with one line — via CompanyContext::runFor since
     * BillLine::booted()'s freeze guard needs a live company context.
     * Mirrors InvoiceShowTest::draftInvoiceWithLine/ExpenseShowTest::
     * draftExpenseWithLine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function draftBillWithLine(Company $company, Contact $contact, Account $category, array $overrides = []): Bill
    {
        $bill = Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ], $overrides));

        app(CompanyContext::class)->runFor($company, fn () => BillLine::factory()->create([
            'bill_id' => $bill->id,
            'company_id' => $company->id,
            'category_account_id' => $category->id,
            'description' => 'Cloud hosting',
            'amount' => 50000,
            'line_tax' => 0,
        ]));

        return $bill;
    }

    public function test_show_renders_a_draft_bill_with_its_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company, ['name' => 'AWS']);
        $category = $this->categoryAccount($company, ['code' => '5100', 'name' => 'Hosting']);

        $bill = $this->draftBillWithLine($company, $vendor, $category, ['reference' => 'INV-9001']);

        $response = $this->get("/bills/{$bill->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bill) {
            $page->component('Bills/Show');
            $page->where('bill.id', $bill->id);
            $page->where('bill.reference', 'INV-9001');
            $page->where('bill.status', 'draft');
            $page->where('bill.is_opening', false);
            $page->where('bill.contact.name', 'AWS');
            $page->has('bill.lines', 1);
            $page->where('bill.lines.0.description', 'Cloud hosting');
            $page->where('bill.lines.0.category_account.name', 'Hosting');
            $page->has('bill.payments', 0);
            $page->has('bankAccounts');
        });
    }

    public function test_show_shares_the_narrower_action_abilities_for_an_owner(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);
        $bill = $this->draftBillWithLine($company, $vendor, $category);

        $this->get("/bills/{$bill->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['bills.receive'] === true
                && $can['payments.record'] === true
                && $can['bills.void'] === true)
        );
    }

    public function test_show_hides_the_narrower_action_abilities_for_staff(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);
        $bill = $this->draftBillWithLine($company, $vendor, $category);

        $this->get("/bills/{$bill->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['bills.receive'] === false
                && $can['payments.record'] === false
                && $can['bills.void'] === false)
        );
    }

    public function test_show_includes_a_posted_bills_payments_with_the_bank_account_name(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company, ['name' => 'Meezan Current']);

        $bill = $this->draftBillWithLine($company, $vendor, $category, ['due_date' => '2026-08-01']);
        $bill->forceFill([
            'status' => BillStatus::PartiallyPaid->value,
            'subtotal' => 50000, 'tax_total' => 0, 'total' => 50000,
            'amount_paid' => 20000, 'balance' => 30000,
            'received_at' => now(),
        ])->save();

        BillPayment::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
            'bank_account_id' => $bank->id,
            'amount' => 20000,
            'status' => 'posted',
        ]);

        $response = $this->get("/bills/{$bill->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->where('bill.status', 'partially_paid');
            $page->where('bill.balance', 30000);
            $page->has('bill.payments', 1);
            $page->where('bill.payments.0.amount', 20000);
            $page->where('bill.payments.0.status', 'posted');
            $page->where('bill.payments.0.bank_account.name', 'Meezan Current');
        });
    }

    public function test_show_flags_an_is_opening_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'status' => BillStatus::Received->value,
            'is_opening' => true,
            'total' => 20000,
            'balance' => 20000,
        ]);

        $this->get("/bills/{$bill->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bill.is_opening', true)
        );
    }

    public function test_show_includes_due_date_and_status_supporting_the_overdue_computation(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBillWithLine($company, $vendor, $category, ['due_date' => '2026-01-01']);
        $bill->forceFill(['status' => BillStatus::Received->value, 'total' => 50000, 'balance' => 50000])->save();

        $this->get("/bills/{$bill->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bill.due_date', '2026-01-01')
            ->where('bill.status', 'received')
        );
    }

    public function test_show_404s_for_a_different_companys_bill(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $contactB = Contact::factory()->create(['company_id' => $companyB->id, 'type' => ContactType::Vendor->value]);

        $foreignBill = Bill::factory()->create([
            'company_id' => $companyB->id,
            'contact_id' => $contactB->id,
        ]);

        $this->get("/bills/{$foreignBill->id}")->assertNotFound();
    }
}
