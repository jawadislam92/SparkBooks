<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountBalances;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
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

    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** Posts a balanced two-line entry directly via LedgerService, inside the caller's transaction, as the brief requires. */
    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, string $date, int $sourceId, string $memo = 'Test entry'): void
    {
        DB::transaction(function () use ($debitAccount, $creditAccount, $amount, $date, $sourceId, $memo) {
            app(LedgerService::class)->post(new EntryDraft(
                entryDate: CarbonImmutable::parse($date),
                memo: $memo,
                sourceType: 'manual_journal',
                sourceId: $sourceId,
                lines: [
                    new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0),
                    new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount),
                ],
            ));
        });
    }

    // ---------------------------------------------------------------
    // AccountBalances service
    // ---------------------------------------------------------------

    public function test_debit_normal_account_with_net_debit_shows_positive_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $balances = app(AccountBalances::class)->asOf();
        $this->assertSame(10000, $balances[$bank->id]);
        $this->assertLedgerBalances();
    }

    public function test_credit_normal_account_with_net_credit_shows_positive_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $balances = app(AccountBalances::class)->asOf();
        $this->assertSame(10000, $balances[$income->id]);
        $this->assertLedgerBalances();
    }

    public function test_as_of_filters_by_entry_date(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($bank, $income, 5000, '2026-07-15', 2);

        $asOfMidMonth = app(AccountBalances::class)->asOf(CarbonImmutable::parse('2026-07-10'));
        $this->assertSame(10000, $asOfMidMonth[$bank->id]);

        $full = app(AccountBalances::class)->asOf();
        $this->assertSame(15000, $full[$bank->id]);
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function test_index_groups_accounts_by_type_with_current_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $response = $this->get('/accounts');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bank, $income) {
            $page->component('Accounts/Index');
            $page->has('accounts', 2);
            $page->where('accounts', function ($accounts) use ($bank, $income) {
                $byId = collect($accounts)->keyBy('id');

                return $byId[$bank->id]['type'] === 'asset'
                    && $byId[$bank->id]['balance'] === 10000
                    && $byId[$bank->id]['subtype_label'] === 'Bank'
                    && $byId[$income->id]['type'] === 'income'
                    && $byId[$income->id]['balance'] === 10000
                    && $byId[$income->id]['subtype_label'] === 'Income';
            });
        });
    }

    public function test_staff_forbidden_from_viewing_index(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $response = $this->get('/accounts');

        $response->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_owner_can_create_account_deriving_type_from_subtype(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->postJson('/accounts', [
            'code' => '1200',
            'name' => 'Petty Cash',
            'subtype' => AccountSubtype::Cash->value,
            'description' => 'Front desk float',
        ]);

        $response->assertRedirect(route('accounts.index'));

        $account = Account::query()->where('code', '1200')->sole();
        $this->assertSame('Petty Cash', $account->name);
        $this->assertSame(AccountType::Asset, $account->type);
        $this->assertSame(AccountSubtype::Cash, $account->subtype);
        $this->assertFalse($account->is_system);
        $this->assertSame('Front desk float', $account->description);

        $audit = ActivityLog::query()->where('action', 'account.created')->sole();
        $this->assertSame($account->id, $audit->subject_id);
    }

    public function test_create_rejects_duplicate_code_within_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->bankAccount($company, ['code' => '1010']);

        $response = $this->postJson('/accounts', [
            'code' => '1010',
            'name' => 'Second Bank',
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Account::query()->where('code', '1010')->count());
    }

    public function test_create_allows_duplicate_code_across_companies(): void
    {
        $companyA = Company::factory()->create();
        $this->bankAccount($companyA, ['code' => '1010']);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $response = $this->postJson('/accounts', [
            'code' => '1010',
            'name' => 'B Bank',
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response->assertRedirect(route('accounts.index'));
        $this->assertSame(2, Account::query()->withoutGlobalScopes()->where('code', '1010')->count());
    }

    public function test_create_rejects_malformed_code(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        foreach (['12', '12345', 'abcd', ''] as $badCode) {
            $response = $this->postJson('/accounts', [
                'code' => $badCode,
                'name' => 'Bad Code Account',
                'subtype' => AccountSubtype::Cash->value,
            ]);

            $response->assertSessionHasErrors('code');
        }

        $this->assertSame(0, Account::query()->count());
    }

    public function test_create_ignores_client_supplied_type_and_is_system(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->postJson('/accounts', [
            'code' => '1300',
            'name' => 'Spoofed Account',
            'subtype' => AccountSubtype::Cash->value, // resolves to Asset
            'type' => AccountType::Liability->value,   // must be ignored
            'is_system' => true,                        // must be ignored
        ]);

        $response->assertRedirect(route('accounts.index'));

        $account = Account::query()->where('code', '1300')->sole();
        $this->assertSame(AccountType::Asset, $account->type);
        $this->assertFalse($account->is_system);
    }

    public function test_staff_forbidden_from_creating_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $response = $this->postJson('/accounts', [
            'code' => '1300',
            'name' => 'Nope',
            'subtype' => AccountSubtype::Cash->value,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Account::query()->count());
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_update_system_account_allows_rename_and_description(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['is_system' => true]);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => 'Main Bank Account',
            'description' => 'Primary operating account',
        ]);

        $response->assertRedirect(route('accounts.index'));

        $fresh = $account->fresh();
        $this->assertSame('Main Bank Account', $fresh->name);
        $this->assertSame('Primary operating account', $fresh->description);

        $audit = ActivityLog::query()->where('action', 'account.updated')->sole();
        $this->assertSame($account->id, $audit->subject_id);
    }

    public function test_update_system_account_rejects_code_change(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['is_system' => true]);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => $account->name,
            'code' => '9999',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame('1010', $account->fresh()->code);
    }

    public function test_update_system_account_rejects_subtype_change(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['is_system' => true]);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => $account->name,
            'subtype' => AccountSubtype::Cash->value,
        ]);

        $response->assertSessionHasErrors('subtype');
        $this->assertSame(AccountSubtype::Bank, $account->fresh()->subtype);
    }

    public function test_update_non_system_account_allows_code_and_subtype_change(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['is_system' => false, 'code' => '9000']);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => 'Renamed',
            'code' => '9001',
            'subtype' => AccountSubtype::CurrentAsset->value,
        ]);

        $response->assertRedirect(route('accounts.index'));

        $fresh = $account->fresh();
        $this->assertSame('9001', $fresh->code);
        $this->assertSame(AccountType::Asset, $fresh->type);
        $this->assertSame(AccountSubtype::CurrentAsset, $fresh->subtype);
    }

    public function test_update_rejects_is_system_field_even_for_non_system_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['is_system' => false, 'code' => '9000']);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => 'Renamed',
            'code' => '9000',
            'subtype' => AccountSubtype::Bank->value,
            'is_system' => true,
        ]);

        $response->assertSessionHasErrors('is_system');
        $this->assertFalse($account->fresh()->is_system);
    }

    public function test_staff_forbidden_from_updating_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $account = Account::factory()->create(['company_id' => $company->id]);

        $response = $this->patchJson("/accounts/{$account->id}", [
            'name' => 'Nope',
        ]);

        $response->assertForbidden();
    }

    public function test_cross_company_account_returns_404_on_update(): void
    {
        $companyA = Company::factory()->create();
        $accountA = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $response = $this->patchJson("/accounts/{$accountA->id}", [
            'name' => 'Hijacked',
            'code' => '1010',
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response->assertNotFound();
    }

    public function test_cross_company_account_returns_404_on_show(): void
    {
        $companyA = Company::factory()->create();
        $accountA = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $response = $this->get("/accounts/{$accountA->id}");

        $response->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Archive / unarchive
    // ---------------------------------------------------------------

    public function test_archive_hides_account_from_manual_journal_options_and_unarchive_restores_it(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);

        $archiveResponse = $this->postJson("/accounts/{$account->id}/archive");
        $archiveResponse->assertRedirect(route('accounts.index'));
        $this->assertTrue($account->fresh()->is_archived);

        $optionsResponse = $this->get('/journal-entries/new');
        $optionsResponse->assertInertia(function (AssertableInertia $page) use ($account) {
            $page->where('accounts', function ($accounts) use ($account) {
                return ! in_array($account->id, collect($accounts)->pluck('id')->all(), true);
            });
        });

        $auditArchive = ActivityLog::query()->where('action', 'account.archived')->sole();
        $this->assertSame($account->id, $auditArchive->subject_id);

        $unarchiveResponse = $this->postJson("/accounts/{$account->id}/unarchive");
        $unarchiveResponse->assertRedirect(route('accounts.index'));
        $this->assertFalse($account->fresh()->is_archived);

        $optionsAfterRestore = $this->get('/journal-entries/new');
        $optionsAfterRestore->assertInertia(function (AssertableInertia $page) use ($account) {
            $page->where('accounts', function ($accounts) use ($account) {
                return in_array($account->id, collect($accounts)->pluck('id')->all(), true);
            });
        });

        $auditUnarchive = ActivityLog::query()->where('action', 'account.unarchived')->sole();
        $this->assertSame($account->id, $auditUnarchive->subject_id);
    }

    public function test_staff_forbidden_from_archiving_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $account = Account::factory()->create(['company_id' => $company->id]);

        $response = $this->postJson("/accounts/{$account->id}/archive");

        $response->assertForbidden();
        $this->assertFalse($account->fresh()->is_archived);
    }

    // ---------------------------------------------------------------
    // Drill-down (Show)
    // ---------------------------------------------------------------

    public function test_show_computes_running_balance_newest_first(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1, 'Opening deposit');
        $this->postEntry($expense, $bank, 3000, '2026-07-05', 2, 'Paid rent');
        $this->postEntry($bank, $income, 2000, '2026-07-10', 3, 'Second deposit');

        $response = $this->get("/accounts/{$bank->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bank) {
            $page->component('Accounts/Show');
            $page->where('account.id', $bank->id);
            $page->where('account.balance', 9000);
            $page->has('ledger.data', 3);
            $page->where('ledger.data.0.running_balance', 9000);
            $page->where('ledger.data.0.memo', 'Second deposit');
            $page->where('ledger.data.1.running_balance', 7000);
            $page->where('ledger.data.1.memo', 'Paid rent');
            $page->where('ledger.data.2.running_balance', 10000);
            $page->where('ledger.data.2.memo', 'Opening deposit');
        });
    }

    // ---------------------------------------------------------------
    // Phase 7 Task 1: sub-accounts — "Add sub-account" HTTP endpoint + tree
    // rendering on Index/Show.
    // ---------------------------------------------------------------

    public function test_owner_can_add_a_sub_account_which_promotes_the_parent(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $parent = $this->expenseAccount($company, ['code' => '5400', 'name' => 'Software']);

        $response = $this->postJson("/accounts/{$parent->id}/children", [
            'code' => '5401',
            'name' => 'Design Tools',
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        $response->assertRedirect(route('accounts.index'));

        $parent->refresh();
        $this->assertFalse($parent->is_postable);

        $child = Account::query()->where('code', '5401')->sole();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertTrue($child->is_postable);
    }

    public function test_adding_a_sub_account_of_the_wrong_root_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $parent = $this->expenseAccount($company, ['code' => '5400']);

        $response = $this->postJson("/accounts/{$parent->id}/children", [
            'code' => '1600',
            'name' => 'Bad Child',
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        $response->assertSessionHasErrors('subtype');
        $this->assertSame(0, Account::query()->where('code', '1600')->count());
        $this->assertTrue($parent->fresh()->is_postable);
    }

    public function test_staff_forbidden_from_adding_a_sub_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $parent = Account::factory()->create(['company_id' => $company->id]);

        $response = $this->postJson("/accounts/{$parent->id}/children", [
            'code' => '9001', 'name' => 'Child', 'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Account::query()->where('code', '9001')->count());
    }

    public function test_index_shows_parent_with_rolled_up_balance_and_leaf_hierarchy_fields(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $parent = $this->expenseAccount($company, ['code' => '5400', 'name' => 'Software']);
        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $this->postEntry($child, $bank, 12000, '2026-07-01', 1);

        $response = $this->get('/accounts');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($parent, $child) {
            $page->component('Accounts/Index');
            $page->where('accounts', function ($accounts) use ($parent, $child) {
                $byId = collect($accounts)->keyBy('id');

                return $byId[$parent->id]['balance'] === 12000
                    && $byId[$parent->id]['is_postable'] === false
                    && $byId[$parent->id]['depth'] === 0
                    && $byId[$child->id]['balance'] === 12000
                    && $byId[$child->id]['is_postable'] === true
                    && $byId[$child->id]['depth'] === 1
                    && $byId[$child->id]['parent_id'] === $parent->id;
            });
        });
    }

    public function test_archiving_a_parent_with_an_active_child_returns_a_friendly_error_not_a_500(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount($company, ['code' => '5400']);
        app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Child', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $response = $this->postJson("/accounts/{$parent->id}/archive");

        $response->assertSessionHasErrors('is_archived');
        $this->assertFalse($parent->fresh()->is_archived);
    }

    public function test_show_on_a_parent_account_displays_its_rolled_up_balance(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $parent = $this->expenseAccount($company, ['code' => '5400', 'name' => 'Software']);
        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);
        $this->postEntry($child, $bank, 5000, '2026-07-01', 1);

        $response = $this->get("/accounts/{$parent->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->where('account.balance', 5000);
            $page->where('account.is_postable', false);
            $page->has('ledger.data', 0);
        });
    }
}
