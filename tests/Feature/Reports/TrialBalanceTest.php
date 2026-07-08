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
use App\Services\Reports\TrialBalanceReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
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

    private function otherIncomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '4100',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
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

    private function liabilityAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::CurrentLiability->value,
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
    // §12.9 invariant: total debits === total credits
    // ---------------------------------------------------------------

    public function test_totals_are_always_equal_across_multiple_accounts_and_entries(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $liability = $this->liabilityAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($expense, $bank, 3000, '2026-07-05', 2);
        $this->postEntry($bank, $liability, 7000, '2026-07-10', 3);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame($report['total_debit'], $report['total_credit']);
        $this->assertGreaterThan(0, $report['total_debit']);
        $this->assertLedgerBalances();
    }

    public function test_totals_are_equal_with_odd_paisa_amounts(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $otherIncome = $this->otherIncomeAccount($company);

        $this->postEntry($bank, $income, 150050, '2026-07-01', 1); // Rs 1500.50
        $this->postEntry($expense, $otherIncome, 3333, '2026-07-02', 2); // Rs 33.33

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame($report['total_debit'], $report['total_credit']);
        $this->assertSame(153383, $report['total_debit']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Per-account row correctness
    // ---------------------------------------------------------------

    public function test_asset_account_with_net_debit_shows_in_debit_column(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $row = collect($report['rows'])->firstWhere('account_id', $bank->id);

        $this->assertNotNull($row);
        $this->assertSame(10000, $row['debit']);
        $this->assertSame(0, $row['credit']);
    }

    public function test_income_account_with_net_credit_shows_in_credit_column(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $row = collect($report['rows'])->firstWhere('account_id', $income->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['debit']);
        $this->assertSame(10000, $row['credit']);
    }

    public function test_liability_account_with_net_credit_shows_in_credit_column(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $liability = $this->liabilityAccount($company);

        $this->postEntry($bank, $liability, 6000, '2026-07-01', 1);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $row = collect($report['rows'])->firstWhere('account_id', $liability->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['debit']);
        $this->assertSame(6000, $row['credit']);
    }

    // ---------------------------------------------------------------
    // as_of filtering
    // ---------------------------------------------------------------

    public function test_as_of_filters_by_entry_date_excluding_later_entries(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($bank, $income, 5000, '2026-07-15', 2);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-10'));

        $bankRow = collect($report['rows'])->firstWhere('account_id', $bank->id);
        $this->assertSame(10000, $bankRow['debit']);
        $this->assertSame(10000, $report['total_debit']);
        $this->assertSame(10000, $report['total_credit']);
    }

    public function test_as_of_includes_entries_dated_exactly_on_the_boundary(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-10', 1);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-10'));

        $bankRow = collect($report['rows'])->firstWhere('account_id', $bank->id);
        $this->assertSame(10000, $bankRow['debit']);
    }

    // ---------------------------------------------------------------
    // Derivation purely from journal_lines + hand-computed sums
    // ---------------------------------------------------------------

    public function test_report_matches_hand_computed_sums_across_repeated_postings(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);

        $this->postEntry($bank, $income, 10000, '2026-07-01', 1);
        $this->postEntry($bank, $income, 2500, '2026-07-03', 2);
        $this->postEntry($expense, $bank, 4000, '2026-07-05', 3);

        // Hand-computed: bank debited 10000+2500=12500, credited 4000 -> net debit 8500.
        // income credited 10000+2500=12500 -> net credit 12500.
        // expense debited 4000 -> net debit 4000.
        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $byId = collect($report['rows'])->keyBy('account_id');

        $this->assertSame(8500, $byId[$bank->id]['debit']);
        $this->assertSame(0, $byId[$bank->id]['credit']);
        $this->assertSame(0, $byId[$income->id]['debit']);
        $this->assertSame(12500, $byId[$income->id]['credit']);
        $this->assertSame(4000, $byId[$expense->id]['debit']);
        $this->assertSame(0, $byId[$expense->id]['credit']);
        $this->assertSame(8500 + 4000, $report['total_debit']);
        $this->assertSame(12500, $report['total_credit']);
    }

    // ---------------------------------------------------------------
    // Skip rules
    // ---------------------------------------------------------------

    public function test_account_with_no_activity_is_skipped(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $unused = $this->liabilityAccount($company, ['code' => '2999']);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));

        $ids = collect($report['rows'])->pluck('account_id')->all();
        $this->assertNotContains($unused->id, $ids);
    }

    public function test_fully_offset_account_is_skipped(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $clearing = $this->liabilityAccount($company, ['code' => '2500', 'subtype' => AccountSubtype::CurrentLiability->value]);
        $bank = $this->bankAccount($company);
        $expense = $this->expenseAccount($company);
        $income = $this->incomeAccount($company);

        // Two opposite entries net the clearing account to exactly zero.
        $this->postEntry($clearing, $bank, 4000, '2026-07-01', 1);
        $this->postEntry($bank, $clearing, 4000, '2026-07-02', 2);

        // Unrelated activity so the report isn't empty.
        $this->postEntry($expense, $income, 1000, '2026-07-03', 3);

        $report = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $ids = collect($report['rows'])->pluck('account_id')->all();

        $this->assertNotContains($clearing->id, $ids);
        $this->assertContains($expense->id, $ids);
        $this->assertContains($income->id, $ids);
        $this->assertSame($report['total_debit'], $report['total_credit']);
    }

    // ---------------------------------------------------------------
    // Cross-company isolation
    // ---------------------------------------------------------------

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

        $response = $this->get('/reports/trial-balance?as_of=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bankA, $bankB) {
            $page->component('Reports/TrialBalance');
            $page->where('report.total_debit', 5000);
            $page->where('report.total_credit', 5000);
            $page->where('report.rows', function ($rows) use ($bankA, $bankB) {
                $ids = collect($rows)->pluck('account_id')->all();

                return in_array($bankB->id, $ids, true) && ! in_array($bankA->id, $ids, true);
            });
        });
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/trial-balance')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/trial-balance/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/trial-balance')->assertOk();
        $this->get('/reports/trial-balance/export.csv')->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        // No role ships in v1 with reports.view but not exports.run (accountant
        // has both). Temporarily strip exports.run from accountant's permission
        // set to prove the export route checks `exports.run` specifically, not
        // merely `reports.view` (which accountant retains and can still view with).
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/reports/trial-balance')->assertOk();
        $this->get('/reports/trial-balance/export.csv')->assertForbidden();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/trial-balance')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/TrialBalance')
            ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // CSV export
    // ---------------------------------------------------------------

    public function test_csv_contains_exact_decimal_strings_for_odd_paisa_values(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $expense = $this->expenseAccount($company);
        $otherIncome = $this->otherIncomeAccount($company);

        $this->postEntry($bank, $income, 150050, '2026-07-01', 1); // Rs 1500.50
        $this->postEntry($expense, $otherIncome, 3333, '2026-07-02', 2); // Rs 33.33

        $response = $this->get('/reports/trial-balance/export.csv?as_of=2026-07-31');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        // Header
        $this->assertSame(['Code', 'Name', 'Debit', 'Credit'], $rows[0]);

        $byCode = [];
        foreach (array_slice($rows, 1) as $row) {
            $byCode[$row[0]] = $row;
        }

        $this->assertSame('1500.50', $byCode['1010'][2]);
        $this->assertSame('0.00', $byCode['1010'][3]);
        $this->assertSame('0.00', $byCode['4000'][2]);
        $this->assertSame('1500.50', $byCode['4000'][3]);
        $this->assertSame('33.33', $byCode['5000'][2]);
        $this->assertSame('0.00', $byCode['5000'][3]);
        $this->assertSame('0.00', $byCode['4100'][2]);
        $this->assertSame('33.33', $byCode['4100'][3]);

        // No float artifacts anywhere in the raw CSV text.
        $this->assertStringNotContainsString('33.3299', $csv);
        $this->assertStringNotContainsString('1500.4999', $csv);
        $this->assertStringNotContainsString('1500.5000000', $csv);
    }

    public function test_csv_totals_row_is_present_and_correct(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postEntry($bank, $income, 12345, '2026-07-01', 1);

        $response = $this->get('/reports/trial-balance/export.csv?as_of=2026-07-31');
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);
        $lastRow = end($rows);

        $this->assertSame('Totals', $lastRow[1]);
        $this->assertSame('123.45', $lastRow[2]);
        $this->assertSame('123.45', $lastRow[3]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/trial-balance/export.csv?as_of=2026-07-15');

        $response->assertOk();
        // Symfony's Response::prepare() appends "; charset=utf-8" to any
        // text/* Content-Type automatically — assert the base type, not the
        // exact string, so this doesn't rot on a framework upgrade.
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('trial-balance-2026-07-15.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/trial-balance/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('trial_balance', $audit->after['report']);
        $this->assertSame('2026-07-15', $audit->after['as_of']);
    }
}
