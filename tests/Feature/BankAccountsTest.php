<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\BankAccountService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class BankAccountsTest extends TestCase
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

    /** @param array{name?:string, type?:string, currency_code?:string} $overrides */
    private function createBankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, function () use ($company, $overrides) {
            return app(BankAccountService::class)->create(array_merge([
                'name' => 'Main Account',
                'type' => 'bank',
                'currency_code' => $company->base_currency,
            ], $overrides));
        });
    }

    // ---------------------------------------------------------------
    // Create + linked ledger account
    // ---------------------------------------------------------------

    public function test_creating_a_bank_account_auto_creates_a_linked_bank_ledger_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/bank-accounts', [
            'name' => 'Meezan Current',
            'type' => 'bank',
            'currency_code' => 'PKR',
        ]);

        $response->assertRedirect(route('bank-accounts.index'));

        app(CompanyContext::class)->set($company);
        $bankAccount = BankAccount::query()->sole();
        $this->assertSame('Meezan Current', $bankAccount->name);
        $this->assertSame('bank', $bankAccount->type);
        $this->assertSame('PKR', $bankAccount->currency_code);
        $this->assertFalse($bankAccount->is_archived);

        $ledgerAccount = $bankAccount->ledgerAccount;
        $this->assertNotNull($ledgerAccount);
        $this->assertSame(AccountSubtype::Bank, $ledgerAccount->subtype);
        $this->assertSame(AccountType::Asset, $ledgerAccount->type);
        $this->assertSame('Meezan Current', $ledgerAccount->name);
        $this->assertFalse($ledgerAccount->is_system);
        $this->assertGreaterThanOrEqual(1000, (int) $ledgerAccount->code);
        $this->assertLessThanOrEqual(1099, (int) $ledgerAccount->code);

        $audit = ActivityLog::query()->where('action', 'bank_account.created')->sole();
        $this->assertSame($bankAccount->id, $audit->subject_id);
    }

    public function test_creating_a_cash_account_auto_creates_a_linked_cash_ledger_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/bank-accounts', [
            'name' => 'Petty Cash',
            'type' => 'cash',
            'currency_code' => 'PKR',
        ])->assertRedirect(route('bank-accounts.index'));

        app(CompanyContext::class)->set($company);
        $bankAccount = BankAccount::query()->sole();
        $this->assertSame('cash', $bankAccount->type);
        $this->assertSame(AccountSubtype::Cash, $bankAccount->ledgerAccount->subtype);
    }

    public function test_linked_account_codes_are_unique_in_the_band_across_several_creations(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        foreach (['First', 'Second', 'Third', 'Fourth'] as $name) {
            $this->post('/bank-accounts', [
                'name' => $name,
                'type' => 'bank',
                'currency_code' => 'PKR',
            ])->assertRedirect(route('bank-accounts.index'));
        }

        app(CompanyContext::class)->set($company);
        $codes = BankAccount::query()->with('ledgerAccount')->get()
            ->map(fn (BankAccount $b) => $b->ledgerAccount->code)
            ->values()
            ->all();

        $this->assertCount(4, $codes);
        $this->assertCount(4, array_unique($codes), 'Every auto-created ledger account code must be unique.');
        foreach ($codes as $code) {
            $this->assertGreaterThanOrEqual(1000, (int) $code);
            $this->assertLessThanOrEqual(1099, (int) $code);
        }
        // Seed already plants 1000/1010 — the service must not collide with those.
        $this->assertNotContains('1000', $codes);
        $this->assertNotContains('1010', $codes);
    }

    // ---------------------------------------------------------------
    // Concurrency: per-company code allocation is lock-serialized
    // ---------------------------------------------------------------

    public function test_code_allocation_reads_the_band_with_a_locking_select(): void
    {
        // Primary concurrency guard: nextAccountCode() reads the company's
        // 1000–1099 band with lockForUpdate() so two simultaneous same-company
        // creates can't pick the same code (the second blocks until the first
        // commits, then reads the new code). A genuine two-connection race is
        // deliberately NOT simulated here — under RefreshDatabase's outer
        // transaction it deadlocks/times out (the reason IdempotencyTest needs
        // its elaborate separate-connection setup), which the task brief flags
        // as expensive/flaky. Instead assert the SELECT is actually a
        // FOR UPDATE, which is what makes the lock hold.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        app(CompanyContext::class)->set($company);
        DB::enableQueryLog();
        $this->createBankAccount($company);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains(strtolower($q), 'from `accounts`')
                && str_contains(strtolower($q), 'for update')),
            'nextAccountCode() must read the accounts band with a locking (FOR UPDATE) select.'
        );
    }

    public function test_two_creates_in_one_transaction_allocate_distinct_codes(): void
    {
        // Proxy for the serialized path: back-to-back allocations (here within
        // one outer transaction) must never reuse a code — the second read
        // sees the first's freshly inserted account and steps past it.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        [$firstCode, $secondCode] = app(CompanyContext::class)->runFor($company, fn () => DB::transaction(function () {
            $service = app(BankAccountService::class);
            $first = $service->create(['name' => 'A', 'type' => 'bank', 'currency_code' => 'PKR']);
            $second = $service->create(['name' => 'B', 'type' => 'bank', 'currency_code' => 'PKR']);

            return [$first->ledgerAccount->code, $second->ledgerAccount->code];
        }));

        $this->assertNotSame(
            $firstCode,
            $secondCode,
            'Two sequential allocations must never reuse the same code.'
        );
    }

    // ---------------------------------------------------------------
    // Band exhaustion → friendly validation error (not a 500)
    // ---------------------------------------------------------------

    public function test_exhausting_the_code_band_returns_a_friendly_validation_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        // Fill every code in the 1000–1099 band so there is no free code left.
        app(CompanyContext::class)->set($company);
        for ($code = 1000; $code <= 1099; $code++) {
            Account::factory()->create([
                'company_id' => $company->id,
                'code' => (string) $code,
                'type' => AccountType::Asset->value,
                'subtype' => AccountSubtype::CurrentAsset->value,
            ]);
        }

        $response = $this->post('/bank-accounts', [
            'name' => 'One Too Many',
            'type' => 'bank',
            'currency_code' => 'PKR',
        ]);

        // A friendly 422 (redirect-back with a session error), not a 500.
        $response->assertSessionHasErrors('name');
        $this->assertStringContainsString(
            'maximum number of bank and cash accounts',
            (string) session('errors')->first('name')
        );

        // And nothing partial was persisted.
        $this->assertSame(0, BankAccount::query()->count());
    }

    // ---------------------------------------------------------------
    // Balance
    // ---------------------------------------------------------------

    public function test_balance_reflects_the_linked_accounts_ledger_balance(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company);

        app(CompanyContext::class)->set($company);
        $incomeAccount = Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'Deposit',
            'lines' => [
                ['account_id' => $bankAccount->ledger_account_id, 'debit' => 50000, 'credit' => 0],
                ['account_id' => $incomeAccount->id, 'debit' => 0, 'credit' => 50000],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect(route('journal-entries.index'));

        $this->assertSame(50000, $bankAccount->fresh()->balance());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Currency must equal base currency
    // ---------------------------------------------------------------

    public function test_currency_must_equal_the_companys_base_currency(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/bank-accounts', [
            'name' => 'USD Account',
            'type' => 'bank',
            'currency_code' => 'USD',
        ]);

        $response->assertInvalid([
            'currency_code' => 'Bank accounts must use your base currency (PKR) for now.',
        ]);

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, BankAccount::query()->count());
    }

    public function test_currency_validation_is_relative_to_the_companys_own_base_currency(): void
    {
        $company = Company::factory()->create(['base_currency' => 'USD']);
        $this->actingAsRole($company, Role::Owner);

        $this->post('/bank-accounts', [
            'name' => 'PKR Account',
            'type' => 'bank',
            'currency_code' => 'PKR',
        ])->assertInvalid([
            'currency_code' => 'Bank accounts must use your base currency (USD) for now.',
        ]);

        $this->post('/bank-accounts', [
            'name' => 'USD Account',
            'type' => 'bank',
            'currency_code' => 'USD',
        ])->assertRedirect(route('bank-accounts.index'));
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/bank-accounts', ['name' => '', 'type' => 'bank', 'currency_code' => 'PKR'])
            ->assertSessionHasErrors('name');
    }

    public function test_type_must_be_bank_or_cash(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/bank-accounts', ['name' => 'Bad type', 'type' => 'crypto', 'currency_code' => 'PKR'])
            ->assertSessionHasErrors('type');
    }

    // ---------------------------------------------------------------
    // Update: rename only, type/currency immutable
    // ---------------------------------------------------------------

    public function test_rename_updates_the_bank_account_and_its_linked_ledger_account_name(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company, ['name' => 'Old Name']);

        $this->patch("/bank-accounts/{$bankAccount->id}", ['name' => 'New Name'])
            ->assertRedirect(route('bank-accounts.index'));

        $this->assertSame('New Name', $bankAccount->fresh()->name);
        $this->assertSame('New Name', $bankAccount->fresh()->ledgerAccount->name);

        $audit = ActivityLog::query()->where('action', 'bank_account.updated')->sole();
        $this->assertSame($bankAccount->id, $audit->subject_id);
        $this->assertSame('Old Name', $audit->before['name']);
        $this->assertSame('New Name', $audit->after['name']);
    }

    public function test_type_is_immutable_on_update(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company, ['type' => 'bank']);

        $this->patch("/bank-accounts/{$bankAccount->id}", ['name' => 'Renamed', 'type' => 'cash'])
            ->assertInvalid(['type' => 'The type field cannot be changed after creation.']);

        $this->assertSame('bank', $bankAccount->fresh()->type);
    }

    public function test_currency_is_immutable_on_update(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company, ['currency_code' => 'PKR']);

        $this->patch("/bank-accounts/{$bankAccount->id}", ['name' => 'Renamed', 'currency_code' => 'USD'])
            ->assertInvalid(['currency_code' => 'The currency_code field cannot be changed after creation.']);

        $this->assertSame('PKR', $bankAccount->fresh()->currency_code);
    }

    // ---------------------------------------------------------------
    // Archive / unarchive
    // ---------------------------------------------------------------

    public function test_archive_and_unarchive_a_bank_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company);

        $this->post("/bank-accounts/{$bankAccount->id}/archive")->assertRedirect(route('bank-accounts.index'));
        $this->assertTrue($bankAccount->fresh()->is_archived);

        $archiveAudit = ActivityLog::query()->where('action', 'bank_account.archived')->sole();
        $this->assertSame($bankAccount->id, $archiveAudit->subject_id);

        $this->post("/bank-accounts/{$bankAccount->id}/unarchive")->assertRedirect(route('bank-accounts.index'));
        $this->assertFalse($bankAccount->fresh()->is_archived);

        $unarchiveAudit = ActivityLog::query()->where('action', 'bank_account.unarchived')->sole();
        $this->assertSame($bankAccount->id, $unarchiveAudit->subject_id);
    }

    public function test_archiving_a_bank_account_does_not_archive_its_linked_ledger_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bankAccount = $this->createBankAccount($company);

        $this->post("/bank-accounts/{$bankAccount->id}/archive")->assertRedirect(route('bank-accounts.index'));

        $this->assertTrue($bankAccount->fresh()->is_archived);
        $this->assertFalse($bankAccount->fresh()->ledgerAccount->is_archived);
    }

    // ---------------------------------------------------------------
    // Tenant scoping
    // ---------------------------------------------------------------

    public function test_cross_company_bank_account_returns_404_on_update(): void
    {
        $companyA = Company::factory()->create();
        $bankAccountA = $this->createBankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->patch("/bank-accounts/{$bankAccountA->id}", ['name' => 'Hijacked'])->assertNotFound();
    }

    public function test_cross_company_bank_account_returns_404_on_archive(): void
    {
        $companyA = Company::factory()->create();
        $bankAccountA = $this->createBankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post("/bank-accounts/{$bankAccountA->id}/archive")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function test_index_lists_bank_accounts_with_balances(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->createBankAccount($company, ['name' => 'Alpha', 'type' => 'bank']);
        $this->createBankAccount($company, ['name' => 'Beta', 'type' => 'cash']);

        $this->get('/bank-accounts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Banking/Index')
                ->has('bankAccounts', 2)
                ->where('bankAccounts.0.balance', 0)
            );
    }

    // ---------------------------------------------------------------
    // Gate: bank_accounts.manage
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_bank_accounts(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            $this->post('/bank-accounts', ['name' => 'Allowed Account', 'type' => 'bank', 'currency_code' => 'PKR'])
                ->assertRedirect(route('bank-accounts.index'));
        }
    }

    public function test_staff_is_forbidden_from_every_bank_account_action(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $bankAccount = $this->createBankAccount($company);

        $this->get('/bank-accounts')->assertForbidden();
        $this->post('/bank-accounts', ['name' => 'Nope', 'type' => 'bank', 'currency_code' => 'PKR'])->assertForbidden();
        $this->patch("/bank-accounts/{$bankAccount->id}", ['name' => 'Nope'])->assertForbidden();
        $this->post("/bank-accounts/{$bankAccount->id}/archive")->assertForbidden();
        $this->post("/bank-accounts/{$bankAccount->id}/unarchive")->assertForbidden();
    }
}
