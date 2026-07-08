<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsSeeder;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private function seedInto(Company $company): void
    {
        app(CompanyContext::class)->runFor(
            $company,
            fn () => app(ChartOfAccountsSeeder::class)->seed($company)
        );
    }

    public function test_seeding_creates_exactly_23_accounts_with_expected_codes(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);

        app(CompanyContext::class)->set($company);
        $this->assertSame(23, Account::query()->count());

        $expectedCodes = [
            '1000', '1010', '1100', '1500', '2000', '2100', '3000', '3100', '3200', '3900',
            '4000', '4900', '5000', '5100', '5200', '5300', '5400', '5500', '5600', '5700',
            '5800', '5900', '5990',
        ];
        $this->assertSame($expectedCodes, Account::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_system_flags_match_spec(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $systemCodes = ['1000', '1010', '1100', '2000', '2100', '3100', '3200'];
        foreach ($systemCodes as $code) {
            $this->assertTrue(
                Account::query()->where('code', $code)->sole()->is_system,
                "expected {$code} to be a system account"
            );
        }

        $nonSystemCodes = [
            '1500', '3000', '3900', '4000', '4900', '5000', '5100', '5200', '5300',
            '5400', '5500', '5600', '5700', '5800', '5900', '5990',
        ];
        foreach ($nonSystemCodes as $code) {
            $this->assertFalse(
                Account::query()->where('code', $code)->sole()->is_system,
                "expected {$code} to not be a system account"
            );
        }
    }

    public function test_names_types_and_subtypes_match_spec_verbatim(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $expected = [
            '1000' => ['Cash on Hand', AccountType::Asset, AccountSubtype::Cash],
            '1010' => ['Bank Account', AccountType::Asset, AccountSubtype::Bank],
            '1100' => ['Accounts Receivable', AccountType::Asset, AccountSubtype::Receivable],
            '1500' => ['Office Equipment', AccountType::Asset, AccountSubtype::FixedAsset],
            '2000' => ['Accounts Payable', AccountType::Liability, AccountSubtype::Payable],
            '2100' => ['Tax Payable', AccountType::Liability, AccountSubtype::Tax],
            '3000' => ["Owner's Equity", AccountType::Equity, AccountSubtype::Equity],
            '3100' => ['Opening Balance Equity', AccountType::Equity, AccountSubtype::Equity],
            '3200' => ['Retained Earnings', AccountType::Equity, AccountSubtype::Equity],
            '3900' => ['Owner Drawings', AccountType::Equity, AccountSubtype::Equity],
            '4000' => ['Service Income', AccountType::Income, AccountSubtype::OperatingIncome],
            '4900' => ['Other Income', AccountType::Income, AccountSubtype::OtherIncome],
            '5000' => ['Salaries & Wages', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5100' => ['Rent', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5200' => ['Utilities', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5300' => ['Internet & Phone', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5400' => ['Software & Subscriptions', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5500' => ['Marketing & Advertising', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5600' => ['Office Supplies', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5700' => ['Bank Fees & Charges', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5800' => ['Travel', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5900' => ['Professional Fees', AccountType::Expense, AccountSubtype::OperatingExpense],
            '5990' => ['Miscellaneous Expense', AccountType::Expense, AccountSubtype::OperatingExpense],
        ];

        foreach ($expected as $code => [$name, $type, $subtype]) {
            $account = Account::query()->where('code', $code)->sole();
            $this->assertSame($name, $account->name, "name mismatch for {$code}");
            $this->assertSame($type, $account->type, "type mismatch for {$code}");
            $this->assertSame($subtype, $account->subtype, "subtype mismatch for {$code}");
        }
    }

    public function test_subtype_type_mapping_is_correct(): void
    {
        $this->assertSame(AccountType::Asset, AccountSubtype::Cash->type());
        $this->assertSame(AccountType::Asset, AccountSubtype::Bank->type());
        $this->assertSame(AccountType::Asset, AccountSubtype::Receivable->type());
        $this->assertSame(AccountType::Asset, AccountSubtype::FixedAsset->type());
        $this->assertSame(AccountType::Asset, AccountSubtype::CurrentAsset->type());
        $this->assertSame(AccountType::Liability, AccountSubtype::Payable->type());
        $this->assertSame(AccountType::Liability, AccountSubtype::Tax->type());
        $this->assertSame(AccountType::Liability, AccountSubtype::CurrentLiability->type());
        $this->assertSame(AccountType::Equity, AccountSubtype::Equity->type());
        $this->assertSame(AccountType::Income, AccountSubtype::OperatingIncome->type());
        $this->assertSame(AccountType::Income, AccountSubtype::OtherIncome->type());
        $this->assertSame(AccountType::Expense, AccountSubtype::OperatingExpense->type());
    }

    public function test_code_is_unique_per_company_at_db_level(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        Account::query()->create([
            'code' => '9999', 'name' => 'First', 'is_system' => false,
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::CurrentAsset->value,
        ]);

        $this->expectException(QueryException::class);

        Account::query()->create([
            'code' => '9999', 'name' => 'Second', 'is_system' => false,
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::CurrentAsset->value,
        ]);
    }

    public function test_account_codes_are_reusable_across_companies(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);

        $ctx->set($a);
        Account::query()->create([
            'code' => '9999', 'name' => 'A Account', 'is_system' => false,
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::CurrentAsset->value,
        ]);

        $ctx->runFor($b, function () {
            Account::query()->create([
                'code' => '9999', 'name' => 'B Account', 'is_system' => false,
                'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::CurrentAsset->value,
            ]);
        });

        $ctx->set($a);
        $this->assertSame('A Account', Account::query()->where('code', '9999')->sole()->name);
        $ctx->set($b);
        $this->assertSame('B Account', Account::query()->where('code', '9999')->sole()->name);
    }

    public function test_system_account_cannot_change_code(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();

        $this->expectException(LogicException::class);
        $account->update(['code' => '1001']);
    }

    public function test_system_account_cannot_change_type(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();

        $this->expectException(LogicException::class);
        $account->update(['type' => AccountType::Liability->value]);
    }

    public function test_system_account_cannot_change_subtype(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();

        $this->expectException(LogicException::class);
        $account->update(['subtype' => AccountSubtype::Bank->value]);
    }

    public function test_system_account_cannot_be_demoted_and_retyped_in_one_call(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1100')->sole();

        try {
            $account->update(['is_system' => false, 'code' => '9999']);
            $this->fail('Expected LogicException when demoting and retyping a system account.');
        } catch (LogicException) {
            // expected
        }

        $fresh = Account::query()->where('code', '1100')->sole();
        $this->assertSame('1100', $fresh->code);
        $this->assertTrue($fresh->is_system);
    }

    public function test_system_account_cannot_change_is_system(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();

        $this->expectException(LogicException::class);
        $account->update(['is_system' => false]);
    }

    public function test_system_account_can_be_renamed(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();
        $account->update(['name' => 'Petty Cash', 'description' => 'Front-desk float']);

        $fresh = $account->fresh();
        $this->assertSame('Petty Cash', $fresh->name);
        $this->assertSame('Front-desk float', $fresh->description);
    }

    public function test_system_account_can_be_archived(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $account = Account::query()->where('code', '1000')->sole();
        $account->update(['is_archived' => true]);

        $this->assertTrue($account->fresh()->is_archived);
    }

    public function test_non_system_account_can_change_code_type_and_subtype(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $account = Account::query()->create([
            'code' => '9000', 'name' => 'Custom Asset', 'is_system' => false,
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::CurrentAsset->value,
        ]);

        $account->update([
            'code' => '9001',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::CurrentLiability->value,
        ]);

        $fresh = $account->fresh();
        $this->assertSame('9001', $fresh->code);
        $this->assertSame(AccountType::Liability, $fresh->type);
        $this->assertSame(AccountSubtype::CurrentLiability, $fresh->subtype);
    }

    public function test_delete_always_throws_for_non_system_account(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $account = Account::factory()->create(['is_system' => false]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Accounts are archived, never deleted.');
        $account->delete();
    }

    public function test_delete_always_throws_for_system_account(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);
        $account = Account::query()->where('code', '1000')->sole();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Accounts are archived, never deleted.');
        $account->delete();
    }

    public function test_is_control_account_true_for_receivable_and_payable_only(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        app(CompanyContext::class)->set($company);

        $ar = Account::query()->where('code', '1100')->sole();
        $ap = Account::query()->where('code', '2000')->sole();
        $bank = Account::query()->where('code', '1010')->sole();

        $this->assertTrue($ar->isControlAccount());
        $this->assertTrue($ap->isControlAccount());
        $this->assertFalse($bank->isControlAccount());
    }

    public function test_onboarding_seeds_the_chart_of_accounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.company'), [
            'name' => 'SparkingAsia',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ]);

        $company = $user->fresh()->currentCompany;
        app(CompanyContext::class)->set($company);
        $this->assertSame(23, Account::query()->count());
    }
}
