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
 * HTTP-level coverage for the Transaction Detail by Account route: gating,
 * tenant scoping, and the CSV export shape. The report's own math (the
 * per-account tie-out to LedgerBalances::movement) is covered by
 * TransactionDetailReportTest -- mirrors GeneralLedgerTest's split of
 * concerns.
 */
class TransactionDetailControllerTest extends TestCase
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

        $response = $this->get('/reports/transaction-detail?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($bankB) {
            $page->component('Reports/TransactionDetail');
            $page->where('report.from', '2026-07-01');
            $page->where('report.to', '2026-07-31');
            $page->where('report.accounts', function ($accounts) use ($bankB) {
                $ids = collect($accounts)->pluck('account_id')->all();

                return in_array($bankB->id, $ids, true) && count($ids) === 2; // bank + income for company B only
            });
        });
    }

    public function test_missing_from_and_to_default_to_current_fiscal_year_to_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/transaction-detail');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/TransactionDetail')
            ->has('report.from')
            ->has('report.to')
        );
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_transaction_detail(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/transaction-detail')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_transaction_detail(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/transaction-detail/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/transaction-detail')->assertOk();
        $this->get('/reports/transaction-detail/export.csv')->assertOk();
    }

    public function test_export_gate_is_specifically_exports_run_not_just_reports_view(): void
    {
        config(['permissions.roles.accountant' => array_values(array_diff(
            config('permissions.roles.accountant'),
            ['exports.run']
        ))]);

        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/reports/transaction-detail')->assertOk();
        $this->get('/reports/transaction-detail/export.csv')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // CSV export shape
    // ---------------------------------------------------------------

    public function test_csv_contains_line_subtotal_and_grand_total_rows_with_exact_decimal_strings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);
        $this->postEntry($bank, $income, 150050, '2026-07-05', 1, 'Deposit'); // Rs 1500.50

        $response = $this->get('/reports/transaction-detail/export.csv?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance'], $rows[0]);

        // Bank account: one line then its Subtotal row.
        $bankLineRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === $bank->code && ($r[4] ?? null) !== 'Subtotal');
        $this->assertNotNull($bankLineRow);
        $this->assertSame('1500.50', $bankLineRow[6]);
        $this->assertSame('0.00', $bankLineRow[7]);

        $bankSubtotalRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === $bank->code && ($r[4] ?? null) === 'Subtotal');
        $this->assertNotNull($bankSubtotalRow);
        $this->assertSame('1500.50', $bankSubtotalRow[6]);
        $this->assertSame('1500.50', $bankSubtotalRow[8]); // bank is debit-normal -> net == subtotal debit

        $grandTotalRow = collect($rows)->last();
        $this->assertSame('Grand total', $grandTotalRow[4]);
        $this->assertSame('1500.50', $grandTotalRow[6]);
        $this->assertSame('1500.50', $grandTotalRow[7]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/transaction-detail/export.csv?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('transaction-detail-2026-07-01-to-2026-07-31.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/transaction-detail/export.csv?from=2026-07-01&to=2026-07-31')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('transaction_detail', $audit->after['report']);
        $this->assertSame('2026-07-01', $audit->after['from']);
        $this->assertSame('2026-07-31', $audit->after['to']);
    }
}
