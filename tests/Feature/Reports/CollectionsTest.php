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

class CollectionsTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    public function test_staff_forbidden_from_viewing_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/collections')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/collections/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/collections')->assertOk();
        $this->get('/reports/collections/export.csv')->assertOk();
    }

    public function test_report_only_includes_current_companys_overdue_documents(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 10000, '2026-01-01', '2026-02-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 10000, '2026-01-01');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 4000, '2026-01-01', '2026-02-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 4000, '2026-01-01');

        $response = $this->get('/reports/collections?as_of=2026-07-15');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Collections')
            ->where('report.grand_total', 4000)
        );
    }

    public function test_csv_has_correct_header_and_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company, ['name' => 'Overdue Co', 'email' => 'ap@overdue.test', 'phone' => '555-1234']);
        $invoice = $this->invoice($contact, 12345, '2026-01-01', '2026-02-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 12345, '2026-01-01');

        $response = $this->get('/reports/collections/export.csv?as_of=2026-07-15');
        $response->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv))));

        $this->assertSame(['Customer', 'Email', 'Phone', 'Invoice', 'Due date', 'Days overdue', 'Outstanding'], $rows[0]);
        $this->assertSame('Overdue Co', $rows[1][0]);
        $this->assertSame('ap@overdue.test', $rows[1][1]);
        $this->assertSame('555-1234', $rows[1][2]);
        $this->assertSame('123.45', $rows[1][6]);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/collections/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('collections', $audit->after['report']);
    }
}
