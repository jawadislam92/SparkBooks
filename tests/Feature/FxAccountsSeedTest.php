<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Services\ChartOfAccountsSeeder;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 4 (spec §17.1 Path 2 FX-lite): every company needs a 4950 "FX Gain"
 * and a 5950 "FX Loss" account before USD invoicing can post the settlement
 * gain/loss line. Covers the seeder addition (new companies) and the
 * `sparkbooks:backfill-fx-accounts` command (companies seeded before this
 * task landed).
 */
class FxAccountsSeedTest extends TestCase
{
    use RefreshDatabase;

    private function seedInto(Company $company): void
    {
        app(CompanyContext::class)->runFor(
            $company,
            fn () => app(ChartOfAccountsSeeder::class)->seed($company)
        );
    }

    /** Simulates a pre-Task-4 company by raw-deleting the FX accounts, bypassing the Account model's "never deleted" guard. */
    private function stripFxAccounts(Company $company): void
    {
        DB::table('accounts')->where('company_id', $company->id)->whereIn('code', ['4950', '5950'])->delete();
    }

    // ---------------------------------------------------------------
    // Seeder
    // ---------------------------------------------------------------

    public function test_new_company_has_25_accounts_including_fx_gain_and_loss(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);

        app(CompanyContext::class)->set($company);
        $this->assertSame(25, Account::query()->count());

        $gain = Account::query()->where('code', '4950')->sole();
        $this->assertSame('FX Gain', $gain->name);
        $this->assertSame(AccountType::Income, $gain->type);
        $this->assertSame(AccountSubtype::OtherIncome, $gain->subtype);
        $this->assertTrue($gain->is_system);

        $loss = Account::query()->where('code', '5950')->sole();
        $this->assertSame('FX Loss', $loss->name);
        $this->assertSame(AccountType::Expense, $loss->type);
        $this->assertSame(AccountSubtype::OperatingExpense, $loss->subtype);
        $this->assertTrue($loss->is_system);
    }

    // ---------------------------------------------------------------
    // Backfill command
    // ---------------------------------------------------------------

    public function test_backfill_adds_missing_fx_accounts_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $this->seedInto($company);
        $this->stripFxAccounts($company);

        app(CompanyContext::class)->set($company);
        $this->assertSame(23, Account::query()->count());

        $this->artisan('sparkbooks:backfill-fx-accounts')->assertExitCode(0);

        $this->assertSame(25, Account::query()->count());

        $gain = Account::query()->where('code', '4950')->sole();
        $this->assertTrue($gain->is_system);
        $this->assertSame(AccountType::Income, $gain->type);
        $this->assertSame(AccountSubtype::OtherIncome, $gain->subtype);

        $loss = Account::query()->where('code', '5950')->sole();
        $this->assertTrue($loss->is_system);
        $this->assertSame(AccountType::Expense, $loss->type);
        $this->assertSame(AccountSubtype::OperatingExpense, $loss->subtype);

        $audit = ActivityLog::query()->where('action', 'fx_accounts.backfilled')->sole();
        $this->assertSame($company->id, $audit->company_id);

        // Second run must not create duplicates — the (company_id, code)
        // unique constraint must survive a re-run untouched.
        $this->artisan('sparkbooks:backfill-fx-accounts')->assertExitCode(0);

        $this->assertSame(1, Account::query()->where('code', '4950')->count());
        $this->assertSame(1, Account::query()->where('code', '5950')->count());
        $this->assertSame(25, Account::query()->count());

        // Second run added nothing new for this company, so no second audit row.
        $this->assertSame(1, ActivityLog::query()->where('action', 'fx_accounts.backfilled')->count());
    }

    public function test_backfill_only_touches_companies_missing_fx_accounts(): void
    {
        $missing = Company::factory()->create();
        $this->seedInto($missing);
        $this->stripFxAccounts($missing);

        $complete = Company::factory()->create();
        $this->seedInto($complete);

        $this->artisan('sparkbooks:backfill-fx-accounts')->assertExitCode(0);

        app(CompanyContext::class)->set($missing);
        $this->assertSame(25, Account::query()->count());
        $this->assertSame(1, Account::query()->where('code', '4950')->count());
        $this->assertSame(1, Account::query()->where('code', '5950')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'fx_accounts.backfilled')->count());

        app(CompanyContext::class)->set($complete);
        $this->assertSame(25, Account::query()->count());
        $this->assertSame(1, Account::query()->where('code', '4950')->count());
        $this->assertSame(1, Account::query()->where('code', '5950')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'fx_accounts.backfilled')->count());
    }

    public function test_backfill_exits_zero_when_there_are_no_companies(): void
    {
        $this->assertSame(0, Company::query()->count());

        $this->artisan('sparkbooks:backfill-fx-accounts')->assertExitCode(0);
    }
}
