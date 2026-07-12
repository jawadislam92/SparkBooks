<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 7 Task 7 (spec §11): the in-app Backup & Export screen — process-UI
 * over EXISTING Phase-6 machinery (AccountantPackageService, the CSV export
 * convention, BackupDatabase's own artifact naming) plus a new full-data
 * export escape hatch (App\Services\FullDataExportService). No new
 * accounting logic lives here — this file proves packaging/scoping/gating/
 * audit-logging only, mirroring tests/Feature/AccountantPackageTest.php and
 * tests/Feature/ExportTest.php's own conventions.
 */
class BackupExportTest extends TestCase
{
    use RefreshDatabase;

    private const FULL_EXPORT_ENTRIES = [
        'accounts.csv',
        'contacts.csv',
        'items.csv',
        'journal_entries.csv',
        'journal_lines.csv',
        'invoices.csv',
        'invoice_payments.csv',
        'bills.csv',
        'bill_payments.csv',
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

    /** Posts a balanced two-line entry directly via LedgerService — mirrors AccountantPackageTest::postEntry. */
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

    /** @return array<string, string> zip entry name => raw contents */
    private function readZipEntries(string $binary): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'backup_export_test_');
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

    // ---------------------------------------------------------------
    // The screen itself
    // ---------------------------------------------------------------

    public function test_show_page_renders_with_three_sections_for_owner(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get(route('settings.backup-export'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BackupExport')
                ->has('from')
                ->has('to')
                ->has('backupStatus')
                ->etc());
    }

    public function test_staff_is_forbidden_from_viewing_the_page(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get(route('settings.backup-export'))->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Accountant package download, reused from this screen
    // ---------------------------------------------------------------

    public function test_download_accountant_package_returns_the_package_and_ties_to_the_service(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150000, '2026-03-01', 1);

        $response = $this->get(route('settings.backup-export.accountant-package', ['from' => '2026-01-01', 'to' => '2026-07-11']));
        $response->assertOk();

        $entries = $this->readZipEntries($response->streamedContent());
        $this->assertArrayHasKey('trial-balance.csv', $entries);
        $this->assertArrayHasKey('general-ledger.pdf', $entries);
        $this->assertGreaterThan(0, strlen($entries['trial-balance.csv']));
    }

    public function test_download_accountant_package_writes_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get(route('settings.backup-export.accountant-package', ['from' => '2026-01-01', 'to' => '2026-07-11']))->assertOk();

        $row = ActivityLog::query()->where('action', 'accountant_package.exported')->sole();
        $this->assertNotNull($row->user_id);
        $this->assertSame('2026-01-01', $row->after['from']);
        $this->assertSame('2026-07-11', $row->after['to']);
    }

    // ---------------------------------------------------------------
    // Full data export
    // ---------------------------------------------------------------

    public function test_full_export_zip_contains_expected_csvs_all_non_empty(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150000, '2026-03-01', 1);
        Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Acme Co']);

        $response = $this->get(route('settings.backup-export.full-export'));
        $response->assertOk();

        $entries = $this->readZipEntries($response->streamedContent());
        $this->assertCount(count(self::FULL_EXPORT_ENTRIES), $entries);
        foreach (self::FULL_EXPORT_ENTRIES as $name) {
            $this->assertArrayHasKey($name, $entries, "Missing zip entry: {$name}");
            $this->assertGreaterThan(0, strlen($entries[$name]), "Zip entry {$name} is empty (expected at least a header row).");
        }
    }

    public function test_full_export_reflects_real_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company, ['name' => 'Main Bank Account']);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150050, '2026-03-01', 1);
        Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Acme Co']);

        $response = $this->get(route('settings.backup-export.full-export'));
        $entries = $this->readZipEntries($response->streamedContent());

        $this->assertStringContainsString('Main Bank Account', $entries['accounts.csv']);
        $this->assertStringContainsString('Acme Co', $entries['contacts.csv']);
        $this->assertStringContainsString('1500.50', $entries['journal_lines.csv']);
    }

    public function test_full_export_is_scoped_and_excludes_other_companys_rows(): void
    {
        $companyA = Company::factory()->create(['name' => 'Alpha Traders Co']);
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA, ['name' => 'Alpha Main Bank']);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 111100, '2026-03-01', 1);
        Contact::factory()->create(['company_id' => $companyA->id, 'type' => ContactType::Customer->value, 'name' => 'Alpha Customer']);

        $companyB = Company::factory()->create(['name' => 'Beta Traders Co']);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB, ['name' => 'Beta Main Bank']);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 222200, '2026-03-01', 2);
        Contact::factory()->create(['company_id' => $companyB->id, 'type' => ContactType::Customer->value, 'name' => 'Beta Customer']);

        $this->actingAsRole($companyA, Role::Owner);

        $response = $this->get(route('settings.backup-export.full-export'));
        $response->assertOk();
        $entries = $this->readZipEntries($response->streamedContent());

        foreach (self::FULL_EXPORT_ENTRIES as $name) {
            $this->assertStringNotContainsString('Beta', $entries[$name], "{$name} leaked company B's data.");
            $this->assertStringNotContainsString('2222.00', $entries[$name], "{$name} leaked company B's amount.");
        }
        $this->assertStringContainsString('Alpha Main Bank', $entries['accounts.csv']);
        $this->assertStringContainsString('Alpha Customer', $entries['contacts.csv']);
    }

    public function test_full_export_writes_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get(route('settings.backup-export.full-export'))->assertOk();

        $row = ActivityLog::query()->where('action', 'full_data_export.exported')->sole();
        $this->assertNotNull($row->user_id);
        $this->assertIsArray($row->after['tables'] ?? null);
        $this->assertContains('journal_lines', $row->after['tables']);
    }

    public function test_full_export_content_type_and_filename(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get(route('settings.backup-export.full-export', ['date' => '2026-07-12']));
        $response->assertOk();

        $this->assertStringStartsWith('application/zip', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('full-export-', $disposition);
        $this->assertStringContainsString('2026-07-12.zip', $disposition);
    }

    public function test_full_export_does_not_leak_temp_file_when_audit_logging_fails(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ActivityLog
            {
                throw new \RuntimeException('Simulated audit-log failure.');
            }
        });

        $before = $this->fullExportTempFiles();

        $this->get(route('settings.backup-export.full-export'))->assertStatus(500);

        $leaked = array_values(array_diff($this->fullExportTempFiles(), $before));
        $this->assertSame([], $leaked, 'downloadFullExport() leaked a temp file when audit logging failed after the zip was built.');
    }

    /** @return list<string> */
    private function fullExportTempFiles(): array
    {
        $dir = sys_get_temp_dir();

        return array_values(array_merge(
            glob($dir.'/sparkbooks_full_export_*') ?: [],
            glob($dir.'/sfe*.tmp') ?: [],
        ));
    }

    // ---------------------------------------------------------------
    // Gate: exports.run on every route
    // ---------------------------------------------------------------

    /** @return list<string> */
    private function allBackupExportUrls(): array
    {
        return [
            route('settings.backup-export'),
            route('settings.backup-export.accountant-package'),
            route('settings.backup-export.full-export'),
        ];
    }

    public function test_staff_is_forbidden_from_every_backup_export_route(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        foreach ($this->allBackupExportUrls() as $url) {
            $this->get($url)->assertForbidden("url={$url}");
        }
    }

    public function test_owner_admin_and_accountant_can_reach_every_backup_export_route(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            foreach ($this->allBackupExportUrls() as $url) {
                $this->get($url)->assertOk("role={$role->value} url={$url}");
            }
        }
    }

    // ---------------------------------------------------------------
    // Backup status section
    // ---------------------------------------------------------------

    public function test_backup_status_reports_unavailable_when_no_backup_file_exists(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        config(['backup.local_path' => storage_path('app/backups_test_empty_'.uniqid())]);

        $this->get(route('settings.backup-export'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/BackupExport')
                ->where('backupStatus.available', false)
                ->etc());
    }

    public function test_backup_status_reports_the_latest_backup_when_files_exist(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $dir = storage_path('app/backups_test_'.uniqid());
        mkdir($dir, 0755, true);
        config(['backup.local_path' => $dir]);

        file_put_contents("{$dir}/sparkbooks-backup-2026-07-01_020000.zip", 'fake-old');
        file_put_contents("{$dir}/sparkbooks-backup-2026-07-10_020000.zip", 'fake-new');

        try {
            $this->get(route('settings.backup-export'))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Settings/BackupExport')
                    ->where('backupStatus.available', true)
                    ->where('backupStatus.filename', 'sparkbooks-backup-2026-07-10_020000.zip')
                    ->etc());
        } finally {
            @unlink("{$dir}/sparkbooks-backup-2026-07-01_020000.zip");
            @unlink("{$dir}/sparkbooks-backup-2026-07-10_020000.zip");
            @rmdir($dir);
        }
    }
}
