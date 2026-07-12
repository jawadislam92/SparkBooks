<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

/**
 * Controller/feature layer for Customer Balance Summary + Detail: gates,
 * CSV shapes, audit logging, cross-company scoping. Correctness of the
 * balance math itself is CustomerBalanceReportTest's job (service-level).
 */
class CustomerBalanceTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_summary(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/customer-balance-summary')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_summary(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/customer-balance-summary/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export_summary_and_detail(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);
        $invoice = $this->invoice($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 5000, '2026-06-01');

        $this->get('/reports/customer-balance-summary')->assertOk();
        $this->get('/reports/customer-balance-summary/export.csv')->assertOk();
        $this->get("/reports/customer-balance-detail?contact_id={$contact->id}")->assertOk();
        $this->get("/reports/customer-balance-detail/export.csv?contact_id={$contact->id}")->assertOk();
    }

    public function test_detail_report_is_null_until_a_contact_is_chosen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/customer-balance-detail')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CustomerBalanceDetail')
            ->where('report', null)
        );
    }

    // ---------------------------------------------------------------
    // Cross-company scoping
    // ---------------------------------------------------------------

    public function test_summary_only_includes_current_companys_contacts(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 10000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 10000, '2026-01-01');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 4000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 4000, '2026-01-01');

        $response = $this->get('/reports/customer-balance-summary?as_of=2026-07-01');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CustomerBalanceSummary')
            ->where('report.grand_total', 4000)
        );
    }

    // ---------------------------------------------------------------
    // CSV shape
    // ---------------------------------------------------------------

    public function test_summary_csv_has_correct_header_and_data_and_total_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company, ['name' => 'Acme Co']);
        $invoice = $this->invoice($contact, 12345, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 12345, '2026-06-01');

        $response = $this->get('/reports/customer-balance-summary/export.csv?as_of=2026-07-15');
        $response->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv))));

        $this->assertSame(['Customer', 'Balance'], $rows[0]);
        $this->assertSame(['Acme Co', '123.45'], $rows[1]);
        $this->assertSame(['Total', '123.45'], end($rows));
    }

    public function test_detail_csv_has_correct_header_and_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);
        $invoice = $this->invoice($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 5000, '2026-06-01');

        $response = $this->get("/reports/customer-balance-detail/export.csv?contact_id={$contact->id}&as_of=2026-07-15");
        $response->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv))));

        $this->assertSame(['Date', 'Type', 'Reference', 'Amount', 'Running balance'], $rows[0]);
        $this->assertSame('2026-06-01', $rows[1][0]);
        $this->assertSame('Invoice', $rows[1][1]);
        $this->assertSame('50.00', $rows[1][3]);
        $this->assertSame('50.00', $rows[1][4]);
    }

    public function test_summary_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/customer-balance-summary/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('customer_balance_summary', $audit->after['report']);
        $this->assertSame('2026-07-15', $audit->after['as_of']);
    }
}
