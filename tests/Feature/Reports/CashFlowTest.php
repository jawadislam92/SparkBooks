<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowClass;
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

class CashFlowTest extends TestCase
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
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
            'cf_class' => CashFlowClass::Cash->value,
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
            'cf_class' => CashFlowClass::Operating->value,
        ], $overrides));
    }

    private function fixedAssetAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1500',
            'name' => 'Office Equipment',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
            'cf_class' => CashFlowClass::Investing->value,
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

        $this->get('/reports/cash-flow')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/cash-flow/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/cash-flow?from=2026-07-01&to=2026-07-31')->assertOk();
        $this->get('/reports/cash-flow/export.csv?from=2026-07-01&to=2026-07-31')->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/reports/cash-flow')->assertOk();
        $this->get('/reports/cash-flow/export.csv')->assertForbidden();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/cash-flow')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CashFlow')
            ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // Default period + scoping + report shape
    // ---------------------------------------------------------------

    public function test_default_period_is_used_when_no_dates_given(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/cash-flow');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CashFlow')
            ->has('report.from')
            ->has('report.to')
        );
    }

    public function test_report_only_includes_current_companys_lines(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 10000, '2026-07-01', 1);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 5000, '2026-07-01', 2);

        $response = $this->get('/reports/cash-flow?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CashFlow')
            ->where('report.operating.total', 5000)
            ->where('report.closing_cash', 5000)
            ->where('report.reconciles_to_cash', true)
        );
    }

    public function test_report_ties_out_end_to_end_through_the_controller(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $fixedAsset = $this->fixedAssetAccount($company);

        $this->postEntry($bank, $income, 100000, '2026-07-02', 1);
        $this->postEntry($fixedAsset, $bank, 40000, '2026-07-10', 2);

        $response = $this->get('/reports/cash-flow?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CashFlow')
            ->where('report.operating.total', 100000)
            ->where('report.investing.total', -40000)
            ->where('report.net_change_in_cash', 60000)
            ->where('report.closing_cash', 60000)
            ->where('report.reconciles_to_cash', true)
        );
    }

    // ---------------------------------------------------------------
    // CSV export
    // ---------------------------------------------------------------

    public function test_csv_contains_operating_investing_and_reconciliation_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $fixedAsset = $this->fixedAssetAccount($company);

        $this->postEntry($bank, $income, 100000, '2026-07-02', 1);
        $this->postEntry($fixedAsset, $bank, 40000, '2026-07-10', 2);

        $response = $this->get('/reports/cash-flow/export.csv?from=2026-07-01&to=2026-07-31');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Section', 'Code', 'Name', 'Amount'], $rows[0]);

        $byLabel = [];
        foreach (array_slice($rows, 1) as $row) {
            $byLabel[$row[2]] = $row;
        }

        $this->assertSame('1000.00', $byLabel['Net income'][3]);
        $this->assertSame('1000.00', $byLabel['Net cash from operating activities'][3]);
        $this->assertSame('Investing', $byLabel['Office Equipment'][0]);
        $this->assertSame('-400.00', $byLabel['Office Equipment'][3]);
        $this->assertSame('-400.00', $byLabel['Net cash from investing activities'][3]);
        $this->assertSame('600.00', $byLabel['Net change in cash'][3]);
        $this->assertSame('0.00', $byLabel['Cash at beginning of period'][3]);
        $this->assertSame('600.00', $byLabel['Cash at end of period'][3]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/cash-flow/export.csv?from=2026-07-01&to=2026-07-15');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('cash-flow-2026-07-01-to-2026-07-15.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/cash-flow/export.csv?from=2026-07-01&to=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('cash_flow', $audit->after['report']);
        $this->assertSame('2026-07-01', $audit->after['from']);
        $this->assertSame('2026-07-15', $audit->after['to']);
    }
}
