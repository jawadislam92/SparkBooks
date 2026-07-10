<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
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

    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'name' => 'Bank Account',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'name' => 'Sales',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function retainedEarningsAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '3200',
            'name' => 'Retained Earnings',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
            'is_system' => true,
        ], $overrides));
    }

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
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/balance-sheet')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/balance-sheet/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/balance-sheet')->assertOk();
        $this->get('/reports/balance-sheet/export.csv')->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/reports/balance-sheet')->assertOk();
        $this->get('/reports/balance-sheet/export.csv')->assertForbidden();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/balance-sheet')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/BalanceSheet')
            ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // Default as-of + scoping + report shape
    // ---------------------------------------------------------------

    public function test_default_as_of_is_today_when_none_given(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/balance-sheet');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/BalanceSheet')
            ->where('report.as_of', CarbonImmutable::today()->toDateString())
        );
    }

    public function test_report_only_includes_current_companys_lines(): void
    {
        $companyA = Company::factory()->create(['books_start_date' => '2026-01-01']);
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 10000, '2026-07-01', 1);

        $companyB = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 5000, '2026-07-01', 2);

        $response = $this->get('/reports/balance-sheet?as_of=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bankA, $bankB) {
            $page->component('Reports/BalanceSheet');
            $page->where('report.total_assets', 5000);
            $page->where('report.is_balanced', true);
            $page->where('report.assets.rows', function ($rows) use ($bankA, $bankB) {
                $ids = collect($rows)->pluck('account_id')->all();

                return in_array($bankB->id, $ids, true) && ! in_array($bankA->id, $ids, true);
            });
        });
    }

    public function test_report_is_balanced_end_to_end_through_the_controller(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $re = $this->retainedEarningsAccount($company);

        $this->postEntry($bank, $income, 25000, '2026-07-01', 1);
        $this->postEntry($bank, $re, 1000, '2026-07-02', 2);

        $response = $this->get('/reports/balance-sheet?as_of=2026-07-31');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/BalanceSheet')
            ->where('report.total_assets', 26000)
            ->where('report.total_liabilities_and_equity', 26000)
            ->where('report.is_balanced', true)
            ->where('report.equity.retained_earnings', 1000)
            ->where('report.equity.current_year_earnings', 25000)
        );
    }

    // ---------------------------------------------------------------
    // CSV export
    // ---------------------------------------------------------------

    public function test_csv_contains_assets_liabilities_and_equity_sections(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 100000, '2026-07-01', 1);

        $response = $this->get('/reports/balance-sheet/export.csv?as_of=2026-07-31');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Section', 'Code', 'Name', 'Amount'], $rows[0]);

        $byName = [];
        foreach (array_slice($rows, 1) as $row) {
            $byName[$row[2]] = $row;
        }

        $this->assertSame('Assets', $byName['Bank Account'][0]);
        $this->assertSame('1010', $byName['Bank Account'][1]);
        $this->assertSame('1000.00', $byName['Bank Account'][3]);

        $this->assertSame('1000.00', $byName['Total Assets'][3]);
        $this->assertSame('0.00', $byName['Total Liabilities'][3]);
        $this->assertSame('0.00', $byName['Retained Earnings'][3]);
        $this->assertSame('1000.00', $byName['Current Year Earnings'][3]);
        $this->assertSame('1000.00', $byName['Total Equity'][3]);
        $this->assertSame('1000.00', $byName['Total Liabilities & Equity'][3]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/balance-sheet/export.csv?as_of=2026-07-15');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('balance-sheet-2026-07-15.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/balance-sheet/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('balance_sheet', $audit->after['report']);
        $this->assertSame('2026-07-15', $audit->after['as_of']);
    }
}
