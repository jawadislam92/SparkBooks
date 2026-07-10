<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 Task 9: the Expenses/Show detail page's data contract — the
 * `expense` Inertia prop ExpenseController::show renders, plus the shared
 * `can` map entries (expenses.record/expenses.void) Show.tsx's action bar
 * gates on. Mirrors InvoiceShowTest's own shape/reasoning exactly: every
 * fixture here is hand-rolled directly on the Expense/ExpenseLine rows since
 * this only tests what the CONTROLLER SERIALIZES — the actual record/void
 * posting logic is ExpenseRecordTest/ExpenseVoidTest's concern.
 */
class ExpenseShowTest extends TestCase
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
    private function contact(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge(['company_id' => $company->id], $overrides));
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
     * A draft expense with one line — via CompanyContext::runFor since
     * ExpenseLine::booted()'s freeze guard needs a live company context.
     * Mirrors InvoiceShowTest::draftInvoiceWithLine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function draftExpenseWithLine(Company $company, Account $category, array $overrides = []): Expense
    {
        $expense = Expense::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides));

        app(CompanyContext::class)->runFor($company, fn () => ExpenseLine::factory()->create([
            'expense_id' => $expense->id,
            'company_id' => $company->id,
            'category_account_id' => $category->id,
            'description' => 'Office chairs',
            'amount' => 30000,
            'line_tax' => 0,
        ]));

        return $expense;
    }

    public function test_show_renders_a_draft_expense_with_its_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $payee = $this->contact($company, ['name' => 'Staples']);
        $category = $this->categoryAccount($company, ['code' => '5000', 'name' => 'Office Supplies']);

        $expense = $this->draftExpenseWithLine($company, $category, ['contact_id' => $payee->id]);

        $response = $this->get("/expenses/{$expense->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($expense) {
            $page->component('Expenses/Show');
            $page->where('expense.id', $expense->id);
            $page->where('expense.status', 'draft');
            $page->where('expense.contact.name', 'Staples');
            $page->has('expense.lines', 1);
            $page->where('expense.lines.0.description', 'Office chairs');
            $page->where('expense.lines.0.category_account.code', '5000');
            $page->where('expense.lines.0.category_account.name', 'Office Supplies');
            $page->where('expense.lines.0.amount', 30000);
            $page->has('expense.attachments', 0);
        });
    }

    public function test_show_renders_a_draft_expense_with_no_payee(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpenseWithLine($company, $category, ['contact_id' => null]);

        $this->get("/expenses/{$expense->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expense.contact', null)
        );
    }

    public function test_show_shares_the_narrower_action_abilities_for_an_owner(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);
        $expense = $this->draftExpenseWithLine($company, $category);

        // `can` is a FLAT map whose own keys contain dots — mirrors
        // InvoiceShowTest's identical reasoning for why this reads the whole
        // map rather than a dot-path assertion.
        $this->get("/expenses/{$expense->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['expenses.record'] === true
                && $can['expenses.void'] === true)
        );
    }

    public function test_show_hides_the_narrower_action_abilities_for_staff(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $category = $this->categoryAccount($company);
        $expense = $this->draftExpenseWithLine($company, $category);

        $this->get("/expenses/{$expense->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['expenses.record'] === false
                && $can['expenses.void'] === false)
        );
    }

    public function test_show_includes_a_recorded_expenses_timestamps_and_bank_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);
        $bank = $this->bankAccount($company, ['name' => 'Meezan Current']);

        $expense = $this->draftExpenseWithLine($company, $category, ['bank_account_id' => $bank->id]);
        $expense->forceFill([
            'status' => ExpenseStatus::Recorded->value,
            'subtotal' => 30000, 'tax_total' => 0, 'total' => 30000,
            'recorded_at' => now(),
        ])->save();

        $response = $this->get("/expenses/{$expense->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->where('expense.status', 'recorded');
            $page->where('expense.bank_account.name', 'Meezan Current');
            $page->where('expense.total', 30000);
            $page->has('expense.recorded_at');
            $page->where('expense.voided_at', null);
        });
    }

    public function test_show_includes_a_voided_expenses_timestamp(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $expense = $this->draftExpenseWithLine($company, $category);
        $expense->forceFill([
            'status' => ExpenseStatus::Void->value,
            'recorded_at' => now()->subDay(),
            'voided_at' => now(),
        ])->save();

        $this->get("/expenses/{$expense->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expense.status', 'void')
            ->has('expense.voided_at')
        );
    }

    public function test_show_404s_for_a_different_companys_expense(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);

        $foreignExpense = Expense::factory()->create(['company_id' => $companyB->id]);

        $this->get("/expenses/{$foreignExpense->id}")->assertNotFound();
    }
}
