<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExpenseDraftTest extends TestCase
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

    private function contact(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        // BankAccountFactory's own default already nests a linked Account
        // (see its own doc comment) whose company_id comes from the ambient
        // CompanyContext, not from this call's own company_id override — so
        // this must run inside runFor(), mirroring OtherReceiptsTest/
        // TransfersTest's own identical helper.
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Office chairs',
            'amount' => 10000,
            'tax_rate_id' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $data */
    private function postExpense(array $data): TestResponse
    {
        return $this->post('/expenses', $data);
    }

    /** @param array<string, mixed> $data */
    private function patchExpense(Expense $expense, array $data): TestResponse
    {
        return $this->patch("/expenses/{$expense->id}", $data);
    }

    // ---------------------------------------------------------------
    // Create — totals exact, payee optional
    // ---------------------------------------------------------------

    public function test_owner_can_create_a_draft_with_no_payee_and_a_single_untaxed_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $response = $this->postExpense([
            'contact_id' => null,
            'expense_date' => '2026-07-01',
            'notes' => 'Reimbursed petty cash',
            'lines' => [
                $this->line(['category_account_id' => $category->id, 'amount' => 150000]),
            ],
        ]);

        $expense = Expense::query()->sole();
        $response->assertRedirect(route('expenses.edit', $expense->id));

        $this->assertNull($expense->contact_id);
        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertSame(150000, $expense->subtotal);
        $this->assertSame(0, $expense->tax_total);
        $this->assertSame(150000, $expense->total);

        $line = ExpenseLine::query()->sole();
        $this->assertSame('Office chairs', $line->description);
        $this->assertSame(150000, $line->amount);
        $this->assertSame(0, $line->line_tax);
        $this->assertNull($line->tax_rate_snapshot);

        $audit = ActivityLog::query()->where('action', 'expense.draft_created')->sole();
        $this->assertSame($expense->id, $audit->subject_id);
        $this->assertSame(150000, $audit->after['total']);
    }

    /** Vendor NOT required for an expense payee — any tenant contact type works, incl. a vendor-only one. */
    public function test_a_vendor_only_contact_is_accepted_as_an_expense_payee(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->contact($company, ['type' => ContactType::Vendor->value]);
        $category = $this->categoryAccount($company);

        $this->postExpense([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertRedirect();

        $this->assertSame($vendor->id, Expense::query()->sole()->contact_id);
    }

    /** A customer-only contact is also fine — no type restriction at all on an expense payee. */
    public function test_a_customer_only_contact_is_accepted_as_an_expense_payee(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customer = $this->contact($company, ['type' => ContactType::Customer->value]);
        $category = $this->categoryAccount($company);

        $this->postExpense([
            'contact_id' => $customer->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertRedirect();

        $this->assertSame($customer->id, Expense::query()->sole()->contact_id);
    }

    /** The brief's own worked example: 10,000 net @ 18% -> 1,800 tax, plus an odd-paisa/untaxed mix. */
    public function test_totals_are_computed_exactly_for_a_multi_line_mixed_taxed_and_untaxed_draft(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $response = $this->postExpense([
            'lines' => [
                // 10,000 @ 18% -> 1,800 tax (the brief's own worked example).
                $this->line(['category_account_id' => $category->id, 'amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]),
                // 9,999 (odd paisa) @ 18% -> 1799.82 half-up 1,800 tax.
                $this->line(['category_account_id' => $category->id, 'amount' => 9999, 'tax_rate_id' => $eighteenPercent->id]),
                // Untaxed.
                $this->line(['category_account_id' => $category->id, 'amount' => 50000]),
            ],
        ]);

        $expense = Expense::query()->sole();
        $response->assertRedirect(route('expenses.edit', $expense->id));

        $this->assertSame(69999, $expense->subtotal);
        $this->assertSame(3600, $expense->tax_total);
        $this->assertSame(73599, $expense->total);

        $lines = ExpenseLine::query()->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $this->assertSame(10000, $lines[0]->amount);
        $this->assertSame(1800, $lines[0]->line_tax);
        $this->assertSame(9999, $lines[1]->amount);
        $this->assertSame(1800, $lines[1]->line_tax);
        $this->assertSame(50000, $lines[2]->amount);
        $this->assertSame(0, $lines[2]->line_tax);
    }

    // ---------------------------------------------------------------
    // Category eligibility — representative HTTP-level 422s (the full
    // matrix is CategoryAccountGuardTest's own responsibility)
    // ---------------------------------------------------------------

    public function test_a_receivable_control_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $receivable = $this->categoryAccount($company, [
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $receivable->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_payable_control_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $payable = $this->categoryAccount($company, [
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
        ]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $payable->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_cash_subtype_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $cash = $this->categoryAccount($company, [
            'code' => '1000',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Cash->value,
        ]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $cash->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_bank_subtype_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->categoryAccount($company, [
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $bank->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_an_income_type_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $income = $this->categoryAccount($company, [
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_an_archived_category_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $archived = $this->categoryAccount($company, ['is_archived' => true]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $archived->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_foreign_tenant_category_account_line_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $foreignCategory = $this->categoryAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $foreignCategory->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    /** P15: the fixed-asset enabler — 1500 Office Equipment. */
    public function test_a_fixed_asset_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $officeEquipment = $this->categoryAccount($company, [
            'code' => '1500',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $officeEquipment->id])],
        ])->assertRedirect();

        $this->assertSame(1, Expense::query()->count());
    }

    /** P12: the tax-remittance enabler — 2100 Tax Payable, a NON-control liability. */
    public function test_the_tax_payable_liability_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $taxPayable = $this->categoryAccount($company, [
            'code' => '2100',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
        ]);

        $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $taxPayable->id])],
        ])->assertRedirect();

        $this->assertSame(1, Expense::query()->count());
    }

    /** P13: the owner-drawings enabler — 3900 Owner Drawings, equity. */
    public function test_the_owner_drawings_equity_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $drawings = $this->categoryAccount($company, [
            'code' => '3900',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ]);

        $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $drawings->id])],
        ])->assertRedirect();

        $this->assertSame(1, Expense::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: contact, bank account, tax rate, amount
    // ---------------------------------------------------------------

    public function test_an_archived_contact_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->contact($company, ['is_archived' => true]);
        $category = $this->categoryAccount($company);

        $response = $this->postExpense([
            'contact_id' => $contact->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_foreign_contact_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $foreignContact = $this->contact($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $category = $this->categoryAccount($companyB);

        $response = $this->postExpense([
            'contact_id' => $foreignContact->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_an_archived_bank_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);
        $bankAccount = $this->bankAccount($company, ['is_archived' => true]);

        $response = $this->postExpense([
            'bank_account_id' => $bankAccount->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_bank_account_is_nullable_at_draft(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $this->postExpense([
            'bank_account_id' => null,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertRedirect();

        $this->assertNull(Expense::query()->sole()->bank_account_id);
    }

    public function test_an_archived_tax_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);
        $archivedRate = $this->taxRate($company, ['is_archived' => true]);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $category->id, 'tax_rate_id' => $archivedRate->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.tax_rate_id');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_zero_amount_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $response = $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $category->id, 'amount' => 0])],
        ]);

        $response->assertSessionHasErrors('lines.0.amount');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_at_least_one_line_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->postExpense(['lines' => []]);

        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, Expense::query()->count());
    }

    // ---------------------------------------------------------------
    // Only status=draft is editable/deletable
    // ---------------------------------------------------------------

    public function test_a_draft_can_be_updated_and_lines_are_replaced_wholesale(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $category->id, 'amount' => 10000])],
        ]);
        $expense = Expense::query()->sole();
        $originalLineId = ExpenseLine::query()->sole()->id;

        $newContact = $this->contact($company);
        $response = $this->patchExpense($expense, [
            'contact_id' => $newContact->id,
            'notes' => 'Updated notes',
            'lines' => [
                $this->line(['category_account_id' => $category->id, 'amount' => 20000, 'description' => 'Revised line']),
            ],
        ]);

        $response->assertRedirect(route('expenses.edit', $expense->id));

        $expense->refresh();
        $this->assertSame($newContact->id, $expense->contact_id);
        $this->assertSame('Updated notes', $expense->notes);
        $this->assertSame(20000, $expense->total);

        $lines = ExpenseLine::query()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('Revised line', $lines->first()->description);
        $this->assertNotSame($originalLineId, $lines->first()->id);

        $audit = ActivityLog::query()->where('action', 'expense.draft_updated')->sole();
        $this->assertSame($expense->id, $audit->subject_id);
        $this->assertSame(20000, $audit->after['total']);
    }

    public function test_updating_a_recorded_expense_is_rejected_and_nothing_changes(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $expense = Expense::factory()->create([
            'company_id' => $company->id,
            'status' => ExpenseStatus::Recorded->value,
            'total' => 50000,
        ]);

        $response = $this->patchExpense($expense, [
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(50000, $expense->fresh()->total);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $this->postExpense([
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);
        $expense = Expense::query()->sole();

        $response = $this->delete("/expenses/{$expense->id}");

        $response->assertRedirect(route('expenses.index'));
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, ExpenseLine::query()->count());

        $audit = ActivityLog::query()->where('action', 'expense.draft_deleted')->sole();
        $this->assertSame($expense->id, $audit->subject_id);
    }

    public function test_deleting_a_recorded_expense_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $expense = Expense::factory()->create([
            'company_id' => $company->id,
            'status' => ExpenseStatus::Recorded->value,
        ]);

        $response = $this->delete("/expenses/{$expense->id}");

        $response->assertSessionHasErrors('status');
        $this->assertSame(1, Expense::query()->count());
    }

    // ---------------------------------------------------------------
    // Gate: expenses.draft — all four roles
    // ---------------------------------------------------------------

    public function test_all_four_roles_can_create_a_draft(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant, Role::Staff] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $category = $this->categoryAccount($company);

            $this->postExpense([
                'lines' => [$this->line(['category_account_id' => $category->id])],
            ])->assertRedirect();
        }
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_expense_404s_on_edit_update_and_destroy(): void
    {
        $companyA = Company::factory()->create();
        $foreignExpense = Expense::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $category = $this->categoryAccount($companyB);

        $this->get("/expenses/{$foreignExpense->id}/edit")->assertNotFound();
        $this->patch("/expenses/{$foreignExpense->id}", [
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertNotFound();
        $this->delete("/expenses/{$foreignExpense->id}")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // index / create form options
    // ---------------------------------------------------------------

    public function test_index_lists_drafts_and_recorded_expenses(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $draft = Expense::factory()->create(['company_id' => $company->id, 'status' => ExpenseStatus::Draft->value]);
        $recorded = Expense::factory()->create(['company_id' => $company->id, 'status' => ExpenseStatus::Recorded->value]);

        $response = $this->get('/expenses');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($draft, $recorded) {
            $page->component('Expenses/Index');
            $page->where('expenses', function ($expenses) use ($draft, $recorded) {
                $ids = collect($expenses)->pluck('id')->all();

                return in_array($draft->id, $ids, true) && in_array($recorded->id, $ids, true);
            });
        });
    }

    public function test_create_form_lists_all_contact_types_and_only_eligible_category_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customer = $this->contact($company, ['type' => ContactType::Customer->value]);
        $vendor = $this->contact($company, ['type' => ContactType::Vendor->value]);
        $archivedContact = $this->contact($company, ['is_archived' => true]);
        $eligible = $this->categoryAccount($company);
        $ineligible = $this->categoryAccount($company, [
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);

        $response = $this->get('/expenses/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($customer, $vendor, $archivedContact, $eligible, $ineligible) {
            $page->component('Expenses/Edit');
            $page->where('expense', null);
            $page->where('contacts', function ($contacts) use ($customer, $vendor, $archivedContact) {
                $ids = collect($contacts)->pluck('id')->all();

                return in_array($customer->id, $ids, true)
                    && in_array($vendor->id, $ids, true)
                    && ! in_array($archivedContact->id, $ids, true);
            });
            $page->where('categoryAccounts', function ($accounts) use ($eligible, $ineligible) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($eligible->id, $ids, true) && ! in_array($ineligible->id, $ids, true);
            });
        });
    }
}
