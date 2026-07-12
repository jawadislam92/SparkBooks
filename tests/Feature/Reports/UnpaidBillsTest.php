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

class UnpaidBillsTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    public function test_staff_forbidden_from_viewing_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/unpaid-bills')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/unpaid-bills/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/unpaid-bills')->assertOk();
        $this->get('/reports/unpaid-bills/export.csv')->assertOk();
    }

    public function test_report_only_includes_current_companys_documents(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $apA = $this->apAccount($companyA);
        $expenseA = $this->expenseAccount($companyA);
        $contactA = $this->contact($companyA);
        $billA = $this->bill($contactA, 10000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($apA, $expenseA, $billA, 10000, '2026-06-01');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $apB = $this->apAccount($companyB);
        $expenseB = $this->expenseAccount($companyB);
        $contactB = $this->contact($companyB);
        $billB = $this->bill($contactB, 4000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($apB, $expenseB, $billB, 4000, '2026-06-01');

        $response = $this->get('/reports/unpaid-bills?as_of=2026-07-15');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/UnpaidBills')
            ->where('report.total_outstanding', 4000)
        );
    }

    public function test_csv_has_correct_header_and_rows(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company, ['name' => 'Supplier Co']);
        $bill = $this->bill($contact, 12345, '2026-06-01', '2026-07-01');
        $this->postBillEntry($ap, $expense, $bill, 12345, '2026-06-01');

        $response = $this->get('/reports/unpaid-bills/export.csv?as_of=2026-07-15');
        $response->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv))));

        $this->assertSame(['Vendor', 'Bill', 'Date', 'Due date', 'Original', 'Outstanding'], $rows[0]);
        $this->assertSame('Supplier Co', $rows[1][0]);
        $this->assertSame('123.45', $rows[1][4]);
        $this->assertSame('123.45', $rows[1][5]);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/unpaid-bills/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('unpaid_bills', $audit->after['report']);
    }
}
