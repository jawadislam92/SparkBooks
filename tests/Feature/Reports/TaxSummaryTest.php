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
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * HTTP-level coverage (gate + scoping + CSV shape) for the Tax Summary
 * report -- the report-service-level classification trap itself is covered
 * exhaustively by TaxSummaryReportTest. Mirrors TrialBalanceTest's own
 * shape.
 */
class TaxSummaryTest extends TestCase
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

    private function taxPayableAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
            'is_system' => true,
        ]);
    }

    private function categoryAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);
    }

    /**
     * Posts a balanced two-line entry directly via LedgerService, inside the
     * caller's transaction -- mirrors TrialBalanceTest::postEntry. Neither
     * account here is a control account, so no contact is required.
     */
    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, string $date, int $sourceId, string $sourceType): void
    {
        DB::transaction(function () use ($debitAccount, $creditAccount, $amount, $date, $sourceId, $sourceType) {
            app(LedgerService::class)->post(new EntryDraft(
                entryDate: CarbonImmutable::parse($date),
                memo: 'Test entry',
                sourceType: $sourceType,
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

        $this->get('/reports/tax-summary')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/tax-summary/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/tax-summary?from=2026-07-01&to=2026-07-31')->assertOk();
        $this->get('/reports/tax-summary/export.csv?from=2026-07-01&to=2026-07-31')->assertOk();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/tax-summary?from=2026-07-01&to=2026-07-31')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Reports/TaxSummary')
                ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // Scoped to current company
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_activity(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $taxA = $this->taxPayableAccount($companyA);
        $categoryA = $this->categoryAccount($companyA);
        $this->postEntry($categoryA, $taxA, 1800, '2026-07-05', 1, 'invoice');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $taxB = $this->taxPayableAccount($companyB);
        $categoryB = $this->categoryAccount($companyB);
        $this->postEntry($categoryB, $taxB, 900, '2026-07-05', 2, 'invoice');

        $response = $this->get('/reports/tax-summary?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('Reports/TaxSummary');
            $page->where('report.collected', 900);
        });
    }

    // ---------------------------------------------------------------
    // CSV export shape
    // ---------------------------------------------------------------

    public function test_csv_contains_expected_lines_and_exact_decimal_strings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $tax = $this->taxPayableAccount($company);
        $category = $this->categoryAccount($company);

        $this->postEntry($category, $tax, 1800, '2026-07-05', 1, 'invoice');

        $response = $this->get('/reports/tax-summary/export.csv?from=2026-07-01&to=2026-07-31');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Line', 'Amount'], $rows[0]);

        $byLabel = [];
        foreach (array_slice($rows, 1) as $row) {
            $byLabel[$row[0]] = $row[1];
        }

        $this->assertSame('18.00', $byLabel['Collected (output tax)']);
        $this->assertSame('0.00', $byLabel['Input tax']);
        $this->assertSame('0.00', $byLabel['Tax remittances']);
        $this->assertSame('0.00', $byLabel['Adjustments']);
        $this->assertSame('18.00', $byLabel['Net tax payable']);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/tax-summary/export.csv?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('tax-summary-2026-07-01-to-2026-07-31.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/tax-summary/export.csv?from=2026-07-01&to=2026-07-31')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('tax_summary', $audit->after['report']);
        $this->assertSame('2026-07-01', $audit->after['from']);
        $this->assertSame('2026-07-31', $audit->after['to']);
    }
}
