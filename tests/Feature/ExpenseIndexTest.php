<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 Task 9: Expenses/Index's list-row enrichments — `notes` (search)
 * and `categories` (the row's category-summary column), added alongside the
 * existing `contact_name`/`expense_date`/`total`/`status` fields. Mirrors
 * BillIndexTest's identical shape.
 */
class ExpenseIndexTest extends TestCase
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
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    public function test_index_includes_notes_and_distinct_category_names(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $travel = $this->categoryAccount($company, ['name' => 'Travel']);
        $meals = $this->categoryAccount($company, ['name' => 'Meals']);

        $expense = Expense::factory()->create([
            'company_id' => $company->id,
            'notes' => 'Client dinner in Karachi',
        ]);

        app(CompanyContext::class)->runFor($company, function () use ($expense, $travel, $meals) {
            ExpenseLine::factory()->create([
                'expense_id' => $expense->id,
                'company_id' => $expense->company_id,
                'category_account_id' => $travel->id,
                'amount' => 10000,
            ]);
            // Two lines against the SAME category must collapse to one
            // entry — this is a distinct-names summary, not a per-line list.
            ExpenseLine::factory()->create([
                'expense_id' => $expense->id,
                'company_id' => $expense->company_id,
                'category_account_id' => $travel->id,
                'amount' => 5000,
            ]);
            ExpenseLine::factory()->create([
                'expense_id' => $expense->id,
                'company_id' => $expense->company_id,
                'category_account_id' => $meals->id,
                'amount' => 2000,
            ]);
        });

        $this->get('/expenses')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Expenses/Index')
            ->where('expenses.0.notes', 'Client dinner in Karachi')
            ->where('expenses.0.categories', ['Travel', 'Meals'])
        );
    }

    public function test_index_returns_an_empty_categories_list_for_a_lineless_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        Expense::factory()->create(['company_id' => $company->id]);

        $this->get('/expenses')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expenses.0.categories', [])
        );
    }
}
