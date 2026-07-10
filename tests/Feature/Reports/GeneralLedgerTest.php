<?php

namespace Tests\Feature\Reports;

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
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * HTTP-level coverage for the General Ledger route: gating, tenant
 * scoping, drill-down query params, and the CSV export shape. The report's
 * own math (running balance / opening / closing ties) is covered by
 * GeneralLedgerReportTest -- mirrors TrialBalanceTest's split of concerns.
 */
class GeneralLedgerTest extends TestCase
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

    private function receivableAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ], $overrides));
    }

    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, string $date, int $sourceId, string $memo = 'Test entry', ?int $contactId = null, string $sourceType = 'manual_journal'): void
    {
        DB::transaction(function () use ($debitAccount, $creditAccount, $amount, $date, $sourceId, $memo, $contactId, $sourceType) {
            app(LedgerService::class)->post(new EntryDraft(
                entryDate: CarbonImmutable::parse($date),
                memo: $memo,
                sourceType: $sourceType,
                sourceId: $sourceId,
                lines: [
                    new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0, contactId: $contactId),
                    new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount, contactId: $contactId),
                ],
            ));
        });
    }

    // ---------------------------------------------------------------
    // Route 200 + scoping
    // ---------------------------------------------------------------

    public function test_route_returns_200_and_scopes_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postEntry($bankA, $incomeA, 10000, '2026-07-05', 1);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postEntry($bankB, $incomeB, 5000, '2026-07-05', 2);

        $response = $this->get('/reports/general-ledger?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bankA, $bankB) {
            $page->component('Reports/GeneralLedger');
            $page->where('report.from', '2026-07-01');
            $page->where('report.to', '2026-07-31');
            $page->where('report.accounts', function ($accounts) use ($bankA, $bankB) {
                $ids = collect($accounts)->pluck('account_id')->all();

                return in_array($bankB->id, $ids, true) && ! in_array($bankA->id, $ids, true);
            });
        });
    }

    // ---------------------------------------------------------------
    // Drill-down query params
    // ---------------------------------------------------------------

    public function test_drill_down_query_params_resolve_to_the_filtered_view(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 10000, '2026-07-05', 1);
        $this->postEntry($income, $bank, 2000, '2026-07-06', 2);

        $response = $this->get('/reports/general-ledger?'.http_build_query([
            'account' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bank) {
            $page->where('report.account_id', $bank->id);
            $page->has('report.accounts', 1);
            $page->where('report.accounts.0.account_id', $bank->id);
        });
    }

    public function test_missing_from_and_to_default_to_current_fiscal_year_to_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/general-ledger');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/GeneralLedger')
            ->has('report.from')
            ->has('report.to')
        );
    }

    /**
     * The contact drill-down (reached via the AR/AP aging customer link):
     * report.contact_id must arrive in the payload — it is the ONLY flag the
     * React page keys the "hide the misleading opening/closing balance"
     * conditional off (showBalance = contactId === null), and contactName is
     * resolved for the "showing only X's lines" banner. Lines are narrowed to
     * that one contact.
     */
    public function test_contact_drill_down_sets_contact_id_and_name_and_narrows_lines(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $receivable = $this->receivableAccount($company);
        $income = $this->incomeAccount($company);
        $customerA = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Acme Co']);
        $customerB = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value]);

        $this->postEntry($receivable, $income, 10000, '2026-07-05', 1, 'Invoice A', $customerA->id, 'invoice');
        $this->postEntry($receivable, $income, 5000, '2026-07-06', 2, 'Invoice B', $customerB->id, 'invoice');

        $response = $this->get('/reports/general-ledger?'.http_build_query([
            'account' => $receivable->id,
            'contact' => $customerA->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($receivable, $customerA) {
            $page->where('report.account_id', $receivable->id);
            $page->where('report.contact_id', $customerA->id);
            $page->where('contactName', 'Acme Co');
            $page->has('report.accounts.0.lines', 1);
            $page->where('report.accounts.0.lines.0.contact_id', $customerA->id);
        });
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_general_ledger(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/general-ledger')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_general_ledger(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/general-ledger/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/general-ledger')->assertOk();
        $this->get('/reports/general-ledger/export.csv')->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        // Mirrors TrialBalanceTest's equivalent test: no role ships in v1
        // with reports.view but not exports.run, so temporarily strip
        // exports.run from accountant to prove the export route checks
        // `exports.run` specifically, not merely the group's `reports.view`.
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/reports/general-ledger')->assertOk();
        $this->get('/reports/general-ledger/export.csv')->assertForbidden();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/general-ledger')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/GeneralLedger')
            ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // CSV export shape
    // ---------------------------------------------------------------

    public function test_csv_contains_opening_line_and_closing_rows_with_exact_decimal_strings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150050, '2026-07-05', 1, 'Deposit'); // Rs 1500.50

        $response = $this->get('/reports/general-ledger/export.csv?'.http_build_query([
            'account' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance'], $rows[0]);
        $this->assertSame('Opening balance', $rows[1][4]);
        $this->assertSame('0.00', $rows[1][8]);

        $lineRow = $rows[2];
        $this->assertSame('2026-07-05', $lineRow[2]);
        $this->assertSame('Manual journal #1', $lineRow[4]);
        $this->assertSame('1500.50', $lineRow[6]);
        $this->assertSame('0.00', $lineRow[7]);
        $this->assertSame('1500.50', $lineRow[8]);

        $closingRow = $rows[3];
        $this->assertSame('Closing balance', $closingRow[4]);
        $this->assertSame('1500.50', $closingRow[8]);
    }

    /**
     * Under a contact filter the CSV mirrors the screen: no Balance column,
     * no Opening/Closing rows -- those figures are the whole account's
     * balance netted through only this contact's lines, so they'd be misread
     * as the contact's own balance. The filtered export is an honest
     * per-contact line list, nothing more.
     */
    public function test_contact_filtered_csv_omits_balance_column_and_opening_closing_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $receivable = $this->receivableAccount($company);
        $income = $this->incomeAccount($company);
        $customerA = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Acme Co']);
        $customerB = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value]);

        $this->postEntry($receivable, $income, 10000, '2026-07-05', 1, 'Invoice A', $customerA->id, 'invoice');
        $this->postEntry($receivable, $income, 5000, '2026-07-06', 2, 'Invoice B', $customerB->id, 'invoice');

        $response = $this->get('/reports/general-ledger/export.csv?'.http_build_query([
            'account' => $receivable->id,
            'contact' => $customerA->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        // Header has no Balance column.
        $this->assertSame(['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit'], $rows[0]);
        // No opening/closing balance rows anywhere.
        $sources = array_map(fn (array $row): string => $row[4] ?? '', $rows);
        $this->assertNotContains('Opening balance', $sources);
        $this->assertNotContains('Closing balance', $sources);
        // Only customer A's single line survives.
        $this->assertCount(2, $rows); // header + one line
        $this->assertSame('Acme Co', $rows[1][5]);
        $this->assertSame('100.00', $rows[1][6]);
        $this->assertCount(8, $rows[1]); // no 9th (Balance) cell
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/general-ledger/export.csv?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        // Symfony's Response::prepare() appends "; charset=utf-8" to any
        // text/* Content-Type automatically -- assert the base type, not
        // the exact string, so this doesn't rot on a framework upgrade.
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('general-ledger-2026-07-01-to-2026-07-31.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/general-ledger/export.csv?from=2026-07-01&to=2026-07-31')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('general_ledger', $audit->after['report']);
        $this->assertSame('2026-07-01', $audit->after['from']);
        $this->assertSame('2026-07-31', $audit->after['to']);
    }
}
