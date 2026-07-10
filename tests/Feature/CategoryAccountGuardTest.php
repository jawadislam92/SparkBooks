<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Exceptions\IneligibleCategoryAccount;
use App\Models\Account;
use App\Models\Company;
use App\Support\CategoryAccountGuard;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CategoryAccountGuard is the Phase 4 mirror of OtherReceiptService's own
 * private guardCategoryAccount (Phase 2 T7) — THE guard (per the task
 * brief) that makes tax remittances (P12), owner drawings (P13), and
 * fixed-asset purchases (P15) recordable through ordinary expense/bill
 * screens: it explicitly ALLOWS asset/liability/equity category accounts,
 * unlike OtherReceipt's guard (money-IN) which allows income/equity but has
 * no type restriction at all beyond control/cash-bank.
 *
 * This is the CALLER-INDEPENDENT layer (spec: "dual-layer like OtherReceipt")
 * — it's exercised here directly, bypassing HTTP/FormRequest entirely, so a
 * future direct caller (e.g. ExpenseService::record(), a CSV import) can
 * never post against an ineligible account even if some other entry point
 * forgets to validate first. The FormRequest-level 422 wiring is covered
 * separately in ExpenseDraftTest/BillDraftTest.
 */
class CategoryAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    private function account(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Rejected
    // ---------------------------------------------------------------

    public function test_an_archived_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'is_archived' => true,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    public function test_a_receivable_control_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    public function test_a_payable_control_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    public function test_an_income_type_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    public function test_a_cash_subtype_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '1000',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Cash->value,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    public function test_a_bank_subtype_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $this->expectException(IneligibleCategoryAccount::class);
        CategoryAccountGuard::guard($account);
    }

    // ---------------------------------------------------------------
    // Allowed — the P12/P13/P15 enablers
    // ---------------------------------------------------------------

    public function test_an_ordinary_expense_account_is_allowed(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        CategoryAccountGuard::guard($account);
        $this->addToAssertionCount(1);
    }

    /** P15: a fixed-asset purchase, e.g. 1500 Office Equipment. */
    public function test_a_fixed_asset_account_is_allowed(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '1500',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        CategoryAccountGuard::guard($account);
        $this->addToAssertionCount(1);
    }

    /** P12: a tax remittance against 2100 Tax Payable — a NON-control liability. */
    public function test_the_tax_payable_liability_account_is_allowed(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '2100',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
        ]);

        CategoryAccountGuard::guard($account);
        $this->addToAssertionCount(1);
    }

    /** P13: owner drawings against 3900 Owner Drawings — equity. */
    public function test_the_owner_drawings_equity_account_is_allowed(): void
    {
        $company = Company::factory()->create();
        $account = $this->account($company, [
            'code' => '3900',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ]);

        CategoryAccountGuard::guard($account);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // eligibleQuery() — kept in lockstep with guard()
    // ---------------------------------------------------------------

    public function test_eligible_query_matches_guard_exactly(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $eligible = [
            $this->account($company, ['code' => '5001', 'type' => AccountType::Expense->value, 'subtype' => AccountSubtype::OperatingExpense->value]),
            $this->account($company, ['code' => '1501', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::FixedAsset->value]),
            $this->account($company, ['code' => '2101', 'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Tax->value]),
            $this->account($company, ['code' => '3901', 'type' => AccountType::Equity->value, 'subtype' => AccountSubtype::Equity->value]),
        ];

        $ineligible = [
            $this->account($company, ['code' => '5002', 'type' => AccountType::Expense->value, 'subtype' => AccountSubtype::OperatingExpense->value, 'is_archived' => true]),
            $this->account($company, ['code' => '1101', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Receivable->value]),
            $this->account($company, ['code' => '2001', 'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Payable->value]),
            $this->account($company, ['code' => '4001', 'type' => AccountType::Income->value, 'subtype' => AccountSubtype::OperatingIncome->value]),
            $this->account($company, ['code' => '1001', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Cash->value]),
            $this->account($company, ['code' => '1011', 'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Bank->value]),
        ];

        $foreignCompany = Company::factory()->create();
        $foreignEligible = Account::factory()->create([
            'company_id' => $foreignCompany->id,
            'code' => '5003',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        $ids = CategoryAccountGuard::eligibleQuery()->pluck('id')->all();

        foreach ($eligible as $account) {
            $this->assertContains($account->id, $ids, "Account {$account->code} should be eligible.");
        }
        foreach ($ineligible as $account) {
            $this->assertNotContains($account->id, $ids, "Account {$account->code} should NOT be eligible.");
        }
        $this->assertNotContains($foreignEligible->id, $ids, 'A foreign-tenant account must never appear regardless of its own eligibility.');
    }
}
