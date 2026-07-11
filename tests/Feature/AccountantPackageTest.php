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
use App\Services\LedgerService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\TrialBalanceReport;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6 Task 4 (spec §11): the Accountant Package — "one-click ... This is
 * also the escape hatch". Every figure inside the zip comes straight from
 * the SAME Phase 5 report services (TrialBalanceReport/BalanceSheetReport/
 * ProfitAndLossReport/...) already covered in isolation by
 * tests/Feature/Reports/*Test.php — this file doesn't re-derive their
 * correctness, it proves the PACKAGING (all 12 entries present and
 * non-empty, the numbers tie to those same services, scoping, the gate, and
 * the export-access-logging completeness rule §17.8 already enforced for
 * every other export endpoint in tests/Feature/ExportTest.php).
 */
class AccountantPackageTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ENTRIES = [
        'general-ledger.csv', 'general-ledger.pdf',
        'trial-balance.csv', 'trial-balance.pdf',
        'profit-and-loss.csv', 'profit-and-loss.pdf',
        'balance-sheet.csv', 'balance-sheet.pdf',
        'ar-aging.csv', 'ar-aging.pdf',
        'ap-aging.csv', 'ap-aging.pdf',
    ];

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function expenseAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** Posts a balanced two-line entry directly via LedgerService — mirrors tests/Feature/Reports/TrialBalanceTest::postEntry. */
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

    /** Parses a CSV string into rows (each a list of string cells) — mirrors tests/Feature/ExportTest::csvRows. */
    private function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv), fn (string $l) => $l !== ''));

        return array_map('str_getcsv', $lines);
    }

    /** @return array<string, string> zip entry name => raw contents */
    private function readZipEntries(string $binary): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'accountant_package_test_');
        file_put_contents($tmpPath, $binary);

        $zip = new ZipArchive;
        $zip->open($tmpPath);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromName($name);
        }

        $zip->close();
        unlink($tmpPath);

        return $entries;
    }

    private function downloadPackage(?string $from = null, ?string $to = null)
    {
        $query = array_filter(['from' => $from, 'to' => $to], fn (?string $v) => $v !== null);

        return $this->get(route('reports.accountant-package.download', $query));
    }

    // ---------------------------------------------------------------
    // All 12 entries present, non-empty
    // ---------------------------------------------------------------

    public function test_zip_contains_all_twelve_entries_with_nonempty_content(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150000, '2026-03-01', 1);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $response->assertOk();

        $entries = $this->readZipEntries($response->streamedContent());

        $this->assertCount(12, $entries, 'Expected exactly 12 zip entries.');
        foreach (self::EXPECTED_ENTRIES as $name) {
            $this->assertArrayHasKey($name, $entries, "Missing zip entry: {$name}");
            $this->assertGreaterThan(0, strlen($entries[$name]), "Zip entry {$name} is empty.");
        }
    }

    public function test_pdf_entries_start_with_pdf_header(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 100000, '2026-03-01', 1);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $entries = $this->readZipEntries($response->streamedContent());

        foreach (self::EXPECTED_ENTRIES as $name) {
            if (str_ends_with($name, '.pdf')) {
                $this->assertStringStartsWith('%PDF', $entries[$name], "{$name} does not start with a PDF header.");
            }
        }
    }

    // ---------------------------------------------------------------
    // Numbers tie to the report services (spot-checks per the brief)
    // ---------------------------------------------------------------

    public function test_trial_balance_csv_debits_equal_credits_and_match_service(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 150050, '2026-03-01', 1); // Rs 1500.50
        $this->postEntry($expense, $bank, 3333, '2026-04-01', 2); // Rs 33.33

        $expected = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-11'));

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $entries = $this->readZipEntries($response->streamedContent());
        $rows = $this->csvRows($entries['trial-balance.csv']);
        $lastRow = end($rows);

        $this->assertSame('Totals', $lastRow[1]);
        $this->assertSame($lastRow[2], $lastRow[3], 'Trial balance debits must equal credits.');
        $this->assertSame(Money::minorToDecimalString($expected['total_debit']), $lastRow[2]);
        $this->assertSame(Money::minorToDecimalString($expected['total_credit']), $lastRow[3]);
    }

    public function test_balance_sheet_is_balanced_and_matches_service(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 200000, '2026-03-01', 1);

        $expected = app(BalanceSheetReport::class)->build(CarbonImmutable::parse('2026-07-11'));
        $this->assertTrue($expected['is_balanced']);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $entries = $this->readZipEntries($response->streamedContent());
        $rows = $this->csvRows($entries['balance-sheet.csv']);

        $totalAssetsRow = collect($rows)->first(fn (array $r) => ($r[2] ?? null) === 'Total Assets');
        $totalLiabEquityRow = collect($rows)->first(fn (array $r) => ($r[2] ?? null) === 'Total Liabilities & Equity');

        $this->assertNotNull($totalAssetsRow);
        $this->assertNotNull($totalLiabEquityRow);
        $this->assertSame($totalAssetsRow[3], $totalLiabEquityRow[3], 'Balance sheet must balance.');
        $this->assertSame(Money::minorToDecimalString($expected['total_assets']), $totalAssetsRow[3]);
    }

    public function test_profit_and_loss_net_matches_service(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 500000, '2026-03-01', 1);
        $this->postEntry($expense, $bank, 120000, '2026-04-01', 2);

        $expected = app(ProfitAndLossReport::class)->build(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-07-11'),
        );

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $entries = $this->readZipEntries($response->streamedContent());
        $rows = $this->csvRows($entries['profit-and-loss.csv']);
        $netProfitRow = collect($rows)->first(fn (array $r) => ($r[2] ?? null) === 'Net Profit');

        $this->assertNotNull($netProfitRow);
        $this->assertSame(Money::minorToDecimalString($expected['net_profit']), end($netProfitRow));
    }

    // ---------------------------------------------------------------
    // Gate: exports.run
    // ---------------------------------------------------------------

    public function test_staff_is_forbidden_from_downloading_package(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->downloadPackage()->assertForbidden();
    }

    public function test_staff_is_forbidden_from_viewing_the_page(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get(route('reports.accountant-package'))->assertForbidden();
    }

    public function test_owner_admin_and_accountant_can_download_package(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            $this->downloadPackage()->assertOk("role={$role->value}");
        }
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        // Same technique as TrialBalanceTest::test_export_gate_is_specifically_exports_run_not_just_reports_view:
        // strip exports.run from accountant's own permission set to prove the
        // download route checks `exports.run` specifically, not merely
        // `reports.view` (which accountant retains and can still view the
        // picker page with).
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get(route('reports.accountant-package'))->assertOk();
        $this->downloadPackage()->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Scoped: the package never leaks another tenant's figures
    // ---------------------------------------------------------------

    public function test_package_only_contains_current_companys_data(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Traders Co']);
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA, ['name' => 'Alpha Main Bank']);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 111100, '2026-03-01', 1);

        $companyB = Company::factory()->create(['name' => 'Beta Traders Co']);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB, ['name' => 'Beta Main Bank']);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 222200, '2026-03-01', 2);

        $this->actingAsRole($companyA, Role::Owner);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $response->assertOk();
        $entries = $this->readZipEntries($response->streamedContent());

        foreach (['general-ledger.csv', 'trial-balance.csv', 'profit-and-loss.csv', 'balance-sheet.csv'] as $name) {
            $this->assertStringNotContainsString('Beta', $entries[$name], "{$name} leaked company B's account name.");
            $this->assertStringNotContainsString('2222.00', $entries[$name], "{$name} leaked company B's amount.");
        }
        $this->assertStringContainsString('Alpha Main Bank', $entries['general-ledger.csv']);
        $this->assertStringContainsString('1111.00', $entries['general-ledger.csv']);
    }

    // ---------------------------------------------------------------
    // Audit logging (§17.8)
    // ---------------------------------------------------------------

    public function test_download_writes_accountant_package_exported_audit_row_with_period(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->downloadPackage('2026-01-01', '2026-07-11')->assertOk();

        $row = ActivityLog::query()->where('action', 'accountant_package.exported')->sole();
        $this->assertSame('2026-01-01', $row->after['from']);
        $this->assertSame('2026-07-11', $row->after['to']);
        $this->assertNotNull($row->user_id);
    }

    public function test_viewing_the_page_writes_no_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get(route('reports.accountant-package'))->assertOk();

        $this->assertSame(0, ActivityLog::query()->where('action', 'accountant_package.exported')->count());
    }

    // ---------------------------------------------------------------
    // Resource hygiene: no temp-file leak on the pre-stream failure path
    // ---------------------------------------------------------------

    public function test_download_does_not_leak_temp_file_when_audit_logging_fails(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        // Force the audit-log write to fail AFTER the zip has already been
        // built and written to disk — this exercises the controller's
        // pre-stream failure path (deleteFileAfterSend only covers the success
        // path once streaming finishes, so the catch/@unlink is what must fire
        // here). The temp file must not survive the failed request.
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ActivityLog
            {
                throw new RuntimeException('Simulated audit-log failure.');
            }
        });

        $before = $this->packageTempFiles();

        $this->downloadPackage('2026-01-01', '2026-07-11')->assertStatus(500);

        $leaked = array_values(array_diff($this->packageTempFiles(), $before));
        $this->assertSame([], $leaked, 'download() leaked a temp file when audit logging failed after the zip was built.');
    }

    /** @return list<string> temp files this feature's tempnam() prefix can produce (full prefix on Linux, 3-char-truncated `spa*.tmp` on Windows). */
    private function packageTempFiles(): array
    {
        $dir = sys_get_temp_dir();

        return array_values(array_merge(
            glob($dir.'/sparkbooks_accountant_package_*') ?: [],
            glob($dir.'/spa*.tmp') ?: [],
        ));
    }

    // ---------------------------------------------------------------
    // Empty company
    // ---------------------------------------------------------------

    public function test_empty_company_period_produces_a_valid_zip_with_no_crash(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $response->assertOk();

        $entries = $this->readZipEntries($response->streamedContent());
        $this->assertCount(12, $entries);
        foreach (self::EXPECTED_ENTRIES as $name) {
            $this->assertArrayHasKey($name, $entries);
            $this->assertGreaterThan(0, strlen($entries[$name]));
        }
    }

    // ---------------------------------------------------------------
    // Feature test for the route (Inertia page)
    // ---------------------------------------------------------------

    public function test_show_page_renders_with_expected_props(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get(route('reports.accountant-package', ['from' => '2026-01-01', 'to' => '2026-07-11']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/AccountantPackage')
                ->where('from', '2026-01-01')
                ->where('to', '2026-07-11')
                ->where('canExport', true));
    }

    public function test_show_page_defaults_to_current_fiscal_year_to_date(): void
    {
        $company = Company::factory()->create(); // fiscal_year_start_month = 1
        $this->actingAsRole($company, Role::Owner);
        $today = CarbonImmutable::today();

        $this->get(route('reports.accountant-package'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/AccountantPackage')
                ->where('from', $today->startOfYear()->toDateString())
                ->where('to', $today->toDateString())
                ->etc());
    }

    public function test_content_type_and_filename(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->downloadPackage('2026-01-01', '2026-07-11');
        $response->assertOk();

        $this->assertStringStartsWith('application/zip', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('accountant-package-', $disposition);
        $this->assertStringContainsString('2026-01-01-2026-07-11.zip', $disposition);
    }
}
