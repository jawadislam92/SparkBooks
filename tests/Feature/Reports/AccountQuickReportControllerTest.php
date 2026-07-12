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

/**
 * HTTP-level coverage for the Account QuickReport route: gating, tenant
 * scoping, the drill-down query params other reports (P&L/Balance Sheet/
 * Trial Balance) link in with, and the CSV export shape. The report's own
 * math (opening/closing ties, leaf-vs-parent rollup) is covered by
 * AccountQuickReportTest -- mirrors GeneralLedgerTest's split of concerns.
 */
class AccountQuickReportControllerTest extends TestCase
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
    // Route 200, scoping, and the "no account selected yet" picker state.
    // ---------------------------------------------------------------

    public function test_route_returns_200_and_scopes_the_account_picker_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $bankA = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bankB = $this->bankAccount($companyB);

        $response = $this->get('/reports/account-quickreport');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bankA, $bankB) {
            $page->component('Reports/AccountQuickReport');
            $page->where('report', null);
            $page->where('accounts', function ($accounts) use ($bankA, $bankB) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($bankB->id, $ids, true) && ! in_array($bankA->id, $ids, true);
            });
        });
    }

    public function test_missing_from_and_to_default_to_current_fiscal_year_to_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/account-quickreport');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/AccountQuickReport')
            ->has('from')
            ->has('to')
        );
    }

    // ---------------------------------------------------------------
    // Drill-down query params -- the round-trip a P&L/Balance Sheet/Trial
    // Balance figure link produces.
    // ---------------------------------------------------------------

    public function test_drill_down_query_params_resolve_to_that_accounts_filtered_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 10000, '2026-07-05', 1);
        $this->postEntry($income, $bank, 2000, '2026-07-06', 2);

        // Exactly the href shape ProfitAndLoss.tsx/BalanceSheet.tsx/
        // TrialBalance.tsx build: route('reports.account-quickreport',
        // {account_id, from, to}).
        $response = $this->get('/reports/account-quickreport?'.http_build_query([
            'account_id' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bank) {
            $page->where('report.account_id', $bank->id);
            $page->has('report.lines', 2);
            $page->where('report.lines.0.account_id', $bank->id);
        });
    }

    public function test_drill_down_with_only_to_still_resolves_a_sensible_default_from(): void
    {
        // Mirrors Balance Sheet's own link shape: only `to` (its as-of date)
        // is carried, `from` is left unset.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);

        $response = $this->get('/reports/account-quickreport?'.http_build_query([
            'account_id' => $bank->id,
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.account_id', $bank->id)
            ->where('report.to', '2026-07-31')
            ->has('report.from')
        );
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_account_quickreport(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/account-quickreport')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_account_quickreport(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $bank = $this->bankAccount($company);

        $this->get("/reports/account-quickreport/export.csv?account_id={$bank->id}")->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        $this->get('/reports/account-quickreport')->assertOk();
        $this->get("/reports/account-quickreport/export.csv?account_id={$bank->id}")->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);
        $bank = $this->bankAccount($company);

        $this->get('/reports/account-quickreport')->assertOk();
        $this->get("/reports/account-quickreport/export.csv?account_id={$bank->id}")->assertForbidden();
    }

    public function test_export_without_an_account_id_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        // This app renders validation failures as a redirect-back with
        // flashed session errors for every web route (bootstrap/app.php
        // restricts JSON error rendering to `api/*` only, to support
        // Inertia's own redirect-based form-error flow) -- same convention
        // every other FormRequest test in this codebase asserts against
        // (assertSessionHasErrors), not a 422 JSON body.
        $this->get('/reports/account-quickreport/export.csv')
            ->assertSessionHasErrors('account_id');
    }

    // ---------------------------------------------------------------
    // CSV export shape
    // ---------------------------------------------------------------

    public function test_csv_contains_opening_and_closing_rows_with_exact_decimal_strings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150050, '2026-07-05', 1, 'Deposit'); // Rs 1500.50

        $response = $this->get('/reports/account-quickreport/export.csv?'.http_build_query([
            'account_id' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Sub-account', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance'], $rows[0]);
        $this->assertSame('Opening balance', $rows[1][3]);
        $this->assertSame('0.00', $rows[1][7]);

        $lineRow = $rows[2];
        $this->assertSame('2026-07-05', $lineRow[1]);
        $this->assertSame('1500.50', $lineRow[5]);
        $this->assertSame('0.00', $lineRow[6]);
        $this->assertSame('1500.50', $lineRow[7]);

        $closingRow = $rows[3];
        $this->assertSame('Closing balance', $closingRow[3]);
        $this->assertSame('1500.50', $closingRow[7]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        $response = $this->get('/reports/account-quickreport/export.csv?'.http_build_query([
            'account_id' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString("account-quickreport-{$bank->code}-2026-07-01-to-2026-07-31.csv", $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);

        $this->get('/reports/account-quickreport/export.csv?'.http_build_query([
            'account_id' => $bank->id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]))->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('account_quickreport', $audit->after['report']);
        $this->assertSame($bank->id, $audit->after['account_id']);
        $this->assertSame('2026-07-01', $audit->after['from']);
        $this->assertSame('2026-07-31', $audit->after['to']);
    }
}
