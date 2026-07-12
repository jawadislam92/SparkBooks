<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowClass;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use App\Services\BankAccountService;
use App\Services\ChartOfAccountsSeeder;
use App\Services\LedgerService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\CashFlowReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 7 Task 8 (CRITICAL production bug fix): `accounts.cf_class` used to
 * be set in only THREE places — the add_cf_class_to_accounts migration
 * backfill, ChartOfAccountsSeeder (new companies), and BackfillFxAccounts —
 * and NO runtime account-creation path set it. Any account created after
 * go-live (a 2nd bank account via BankAccountService, a new expense account
 * via AccountController, a sub-account via AccountService::createChild) was
 * born with cf_class=NULL, silently fell out of the Statement of Cash
 * Flows' cash pool, and understated cash / flipped reconciles_to_cash to
 * false.
 *
 * This file covers: the reviewer's exact reproduction (regression), every
 * creation path yielding a correct non-null cf_class, the cash/bank "always
 * Cash" structural invariant (NULL now impossible on any subtype),
 * CashFlowClass::defaultFor()'s mapping (and its lock-step sync with
 * ChartOfAccountsSeeder), and the new cf_class edit-form reclassification
 * flow.
 */
class CfClassFixTest extends TestCase
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

    private function seedChart(Company $company): void
    {
        app(CompanyContext::class)->runFor($company, fn () => app(ChartOfAccountsSeeder::class)->seed($company));
    }

    private function postEntry(Account $debit, Account $credit, int $amount, string $date, int $sourceId, string $sourceType = 'manual_journal'): void
    {
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Test entry',
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $debit->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $credit->id, debit: 0, credit: $amount),
            ],
        )));
    }

    // -----------------------------------------------------------------
    // THE REVIEWER'S EXACT REPRODUCTION (regression)
    //
    // Seed a company, add a 2nd bank account the way BankAccountService
    // really does (BankAccountService::create(), which wraps createOnce() in
    // its own transaction/retry — no test shortcut), transfer money into it.
    // Balance Sheet total cash must equal the SCF's own opening/closing
    // cash, and reconciles_to_cash must be true.
    // -----------------------------------------------------------------

    public function test_a_second_bank_account_added_via_bank_account_service_stays_in_the_scf_cash_pool(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);

        $bank1 = Account::query()->where('code', '1010')->sole();
        $equity = Account::query()->where('code', '3000')->sole();

        // A real opening balance BEFORE the reporting period.
        $this->postEntry($bank1, $equity, 300000, '2026-06-15', 1);

        // The REAL untouched path: BankAccountService::create() — exactly
        // how "Add bank account" works in production. No cf_class is passed
        // anywhere, by design — that is the whole point of this test.
        $bankAccount2 = app(BankAccountService::class)->create([
            'name' => 'Payroll Account',
            'type' => 'bank',
            'currency_code' => $company->base_currency,
        ]);
        $bank2 = $bankAccount2->ledgerAccount;

        // A transfer WITHIN the reporting period: money moves from bank1
        // into the newly created bank2. Total cash is unaffected (still
        // 300000) — just redistributed between two real bank accounts.
        $this->postEntry($bank2, $bank1, 100000, '2026-07-10', 2, 'transfer');

        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-07-31');
        $scf = app(CashFlowReport::class)->build($from, $to);
        $balanceSheet = app(BalanceSheetReport::class)->build($to);

        $bsTotalCash = collect($balanceSheet['assets']['rows'])
            ->whereIn('account_id', [$bank1->id, $bank2->id])
            ->sum('amount');

        // Balance Sheet correctly shows 300000 total cash regardless.
        $this->assertSame(300000, $bsTotalCash);

        // BEFORE THE FIX: bank2's cf_class was NULL, so it never entered the
        // SCF's cash pool. opening_cash (only bank1 existed pre-period) was
        // 300000, but closing_cash only summed bank1's own remaining balance
        // (200000) — bank2's 100000 was invisible to the report entirely,
        // and the transfer's non-cash leg (bank2) was unclassified, so
        // net_change_in_cash stayed 0. 300000 + 0 !== 200000 ->
        // reconciles_to_cash was FALSE. AFTER THE FIX: bank2 is forced
        // cf_class=Cash on creation, so it is part of the pool from the
        // start; the transfer's two legs are both cash-pool, net to zero,
        // and closing_cash correctly reflects both accounts.
        $this->assertSame(300000, $scf['opening_cash']);
        $this->assertSame(300000, $scf['closing_cash']);
        $this->assertSame(0, $scf['net_change_in_cash']);
        $this->assertTrue($scf['reconciles_to_cash']);
        $this->assertSame(0, $scf['diagnostics']['unclassified_count']);
        $this->assertSame([], $scf['diagnostics']['unclassified_lines']);
        $this->assertLedgerBalances();
    }

    // -----------------------------------------------------------------
    // Every creation path yields a correct, non-null cf_class.
    // -----------------------------------------------------------------

    public function test_new_bank_account_via_bank_account_service_create_once_gets_cf_class_cash(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $bankAccount = app(BankAccountService::class)->create([
            'name' => 'Main Account', 'type' => 'bank', 'currency_code' => $company->base_currency,
        ]);

        $this->assertSame(CashFlowClass::Cash, $bankAccount->ledgerAccount->cf_class);
    }

    public function test_new_cash_account_via_bank_account_service_create_once_gets_cf_class_cash(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $bankAccount = app(BankAccountService::class)->create([
            'name' => 'Petty Cash', 'type' => 'cash', 'currency_code' => $company->base_currency,
        ]);

        $this->assertSame(CashFlowClass::Cash, $bankAccount->ledgerAccount->cf_class);
    }

    public function test_new_expense_account_via_account_controller_store_gets_cf_class_operating(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/accounts', [
            'code' => '5995',
            'name' => 'Bank Charges',
            'subtype' => AccountSubtype::OperatingExpense->value,
        ])->assertRedirect(route('accounts.index'));

        $account = Account::query()->where('code', '5995')->sole();
        $this->assertSame(CashFlowClass::Operating, $account->cf_class);
    }

    public function test_new_fixed_asset_account_via_account_controller_store_gets_cf_class_investing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->postJson('/accounts', [
            'code' => '1600',
            'name' => 'Vehicles',
            'subtype' => AccountSubtype::FixedAsset->value,
        ])->assertRedirect(route('accounts.index'));

        $account = Account::query()->where('code', '1600')->sole();
        $this->assertSame(CashFlowClass::Investing, $account->cf_class);
    }

    public function test_child_account_via_account_service_create_child_inherits_parents_cf_class(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        // A parent that has been reclassified away from its ordinary
        // default (Operating) — proves inheritance, not a coincidence.
        $parent = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5400',
            'name' => 'Software & Subscriptions',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'cf_class' => CashFlowClass::Investing->value,
        ]);

        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $this->assertSame(CashFlowClass::Investing, $child->cf_class);
    }

    public function test_child_account_falls_back_to_its_own_subtype_default_when_parent_has_no_cf_class(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5400',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'cf_class' => null,
        ]);
        // The factory's `saving` hook already filled the parent's own
        // cf_class in (Operating, the OperatingExpense default) — refresh to
        // see it, then null it back out directly at the DB layer so the
        // child truly has nothing to inherit.
        DB::table('accounts')->where('id', $parent->id)->update(['cf_class' => null]);

        $child = app(AccountService::class)->createChild($parent->fresh(), [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $this->assertSame(CashFlowClass::Operating, $child->cf_class);
    }

    // -----------------------------------------------------------------
    // The cash/bank "always Cash" structural invariant — NULL is now
    // impossible on any subtype.
    // -----------------------------------------------------------------

    public function test_bank_subtype_account_saved_with_cf_class_operating_ends_up_cash_directly(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $account = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1030',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
            'cf_class' => CashFlowClass::Operating->value,
        ]);

        $this->assertSame(CashFlowClass::Cash, $account->cf_class);

        // A direct mis-edit attempt, after the fact.
        $account->update(['cf_class' => CashFlowClass::Operating->value]);
        $this->assertSame(CashFlowClass::Cash, $account->fresh()->cf_class);
    }

    public function test_cash_subtype_account_can_never_be_saved_with_a_non_cash_cf_class(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $account = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1001',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Cash->value,
            'cf_class' => CashFlowClass::Financing->value,
        ]);

        $this->assertSame(CashFlowClass::Cash, $account->cf_class);
    }

    public function test_bank_subtype_account_cannot_be_reclassified_off_cash_via_the_edit_request(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $account = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1030',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $this->patchJson("/accounts/{$account->id}", [
            'name' => $account->name,
            'code' => $account->code,
            'subtype' => AccountSubtype::Bank->value,
            'cf_class' => CashFlowClass::Operating->value,
        ])->assertRedirect(route('accounts.index'));

        $this->assertSame(CashFlowClass::Cash, $account->fresh()->cf_class);
    }

    public function test_no_subtype_can_ever_be_saved_with_a_null_cf_class(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        foreach (AccountSubtype::cases() as $subtype) {
            $account = Account::factory()->create([
                'company_id' => $company->id,
                'type' => $subtype->type()->value,
                'subtype' => $subtype->value,
                'cf_class' => null,
            ]);

            $this->assertNotNull(
                $account->cf_class,
                "expected subtype {$subtype->value} to never save with a null cf_class"
            );
        }
    }

    // -----------------------------------------------------------------
    // CashFlowClass::defaultFor() — the one canonical mapping.
    // -----------------------------------------------------------------

    public function test_default_for_matches_the_documented_mapping_for_every_subtype(): void
    {
        $expected = [
            'cash' => CashFlowClass::Cash,
            'bank' => CashFlowClass::Cash,
            'receivable' => CashFlowClass::Operating,
            'current_asset' => CashFlowClass::Operating,
            'payable' => CashFlowClass::Operating,
            'tax' => CashFlowClass::Operating,
            'current_liability' => CashFlowClass::Operating,
            'fixed_asset' => CashFlowClass::Investing,
            'equity' => CashFlowClass::Financing,
            'operating_income' => CashFlowClass::Operating,
            'other_income' => CashFlowClass::Operating,
            'operating_expense' => CashFlowClass::Operating,
        ];

        $this->assertSame(count(AccountSubtype::cases()), count($expected), 'every AccountSubtype case must be covered by this test');

        foreach (AccountSubtype::cases() as $subtype) {
            $this->assertSame(
                $expected[$subtype->value],
                CashFlowClass::defaultFor($subtype),
                "defaultFor mismatch for subtype {$subtype->value}"
            );
        }
    }

    public function test_default_for_special_codes_are_always_excluded_regardless_of_subtype(): void
    {
        $this->assertSame(CashFlowClass::Excluded, CashFlowClass::defaultFor(AccountSubtype::Equity, '3200'));
        $this->assertSame(CashFlowClass::Excluded, CashFlowClass::defaultFor(AccountSubtype::OtherIncome, '4950'));
        $this->assertSame(CashFlowClass::Excluded, CashFlowClass::defaultFor(AccountSubtype::OperatingExpense, '5950'));

        // Sanity: the SAME subtype/code without the special code takes the
        // ordinary subtype-based default.
        $this->assertSame(CashFlowClass::Financing, CashFlowClass::defaultFor(AccountSubtype::Equity, '3000'));
        $this->assertSame(CashFlowClass::Operating, CashFlowClass::defaultFor(AccountSubtype::OtherIncome, '4900'));
    }

    /**
     * Locks defaultFor() and ChartOfAccountsSeeder in sync forever — the
     * seeder now SOURCES its cf_class from defaultFor() directly (see that
     * class), so this also doubles as a regression test for that refactor.
     */
    public function test_default_for_matches_chart_of_accounts_seeder_for_every_standard_account(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);

        $expected = [
            '1000' => CashFlowClass::Cash, '1010' => CashFlowClass::Cash,
            '1100' => CashFlowClass::Operating, '1500' => CashFlowClass::Investing,
            '2000' => CashFlowClass::Operating, '2100' => CashFlowClass::Operating,
            '3000' => CashFlowClass::Financing, '3100' => CashFlowClass::Financing,
            '3200' => CashFlowClass::Excluded, '3900' => CashFlowClass::Financing,
            '4000' => CashFlowClass::Operating, '4900' => CashFlowClass::Operating,
            '4950' => CashFlowClass::Excluded,
            '5000' => CashFlowClass::Operating, '5100' => CashFlowClass::Operating,
            '5200' => CashFlowClass::Operating, '5300' => CashFlowClass::Operating,
            '5400' => CashFlowClass::Operating, '5500' => CashFlowClass::Operating,
            '5600' => CashFlowClass::Operating, '5700' => CashFlowClass::Operating,
            '5800' => CashFlowClass::Operating, '5900' => CashFlowClass::Operating,
            '5950' => CashFlowClass::Excluded, '5990' => CashFlowClass::Operating,
        ];

        $this->assertSame(25, count($expected));
        $this->assertSame(25, Account::query()->count());

        foreach ($expected as $code => $class) {
            $account = Account::query()->where('code', $code)->sole();
            $this->assertSame($class, $account->cf_class, "cf_class mismatch for seeded account {$code}");
        }
    }

    // -----------------------------------------------------------------
    // Reclassification via the edit form persists and the SCF honors it.
    // -----------------------------------------------------------------

    public function test_accountant_reclassifies_a_depreciation_expense_account_and_the_scf_honors_it(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $depreciationExpense = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5960',
            'name' => 'Depreciation Expense',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);
        $accumDep = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1590',
            'name' => 'Accumulated Depreciation',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        // Starts life with the ordinary OperatingExpense default.
        $this->assertSame(CashFlowClass::Operating, $depreciationExpense->fresh()->cf_class);

        // The accountant reclassifies it as Investing via the edit form.
        $this->patchJson("/accounts/{$depreciationExpense->id}", [
            'name' => $depreciationExpense->name,
            'code' => $depreciationExpense->code,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'cf_class' => CashFlowClass::Investing->value,
        ])->assertRedirect(route('accounts.index'));

        $this->assertSame(CashFlowClass::Investing, $depreciationExpense->fresh()->cf_class);

        $this->postEntry($depreciationExpense->fresh(), $accumDep, 5000, '2026-07-15', 1);

        $report = app(CashFlowReport::class)->build(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31')
        );

        // The SCF's add-back mechanism (THE DEPRECIATION ADD-BACK RULE) only
        // fires for an Expense account whose cf_class is Investing/Financing
        // — proving the reclassification actually feeds the report.
        $this->assertSame(5000, $report['operating']['add_backs']['total']);
        $addBackRow = collect($report['operating']['add_backs']['rows'])->firstWhere('account_id', $depreciationExpense->id);
        $this->assertNotNull($addBackRow);
        $this->assertSame(5000, $addBackRow['amount']);
    }
}
