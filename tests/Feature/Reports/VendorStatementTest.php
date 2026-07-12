<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

class VendorStatementTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    public function test_staff_forbidden_from_viewing_statement(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/vendor-statement')->assertForbidden();
    }

    public function test_report_is_null_until_a_contact_is_chosen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/vendor-statement')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/VendorStatement')
            ->where('report', null)
        );
    }

    public function test_owner_can_view_statement_for_a_chosen_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company);
        $bill = $this->bill($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($ap, $expense, $bill, 5000, '2026-06-01');

        $response = $this->get("/reports/vendor-statement?contact_id={$contact->id}&from=2026-06-01&to=2026-06-30");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/VendorStatement')
            ->where('report.closing_balance', 5000)
        );
    }

    public function test_pdf_route_renders_successfully(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company);
        $bill = $this->bill($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($ap, $expense, $bill, 5000, '2026-06-01');

        $response = $this->get("/reports/vendor-statement/pdf?contact_id={$contact->id}&from=2026-06-01&to=2026-06-30");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_route_requires_contact_id(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/vendor-statement/pdf')->assertSessionHasErrors('contact_id');
    }

    public function test_statement_scoped_to_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $apA = $this->apAccount($companyA);
        $expenseA = $this->expenseAccount($companyA);
        $contactA = $this->contact($companyA);
        $billA = $this->bill($contactA, 9000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($apA, $expenseA, $billA, 9000, '2026-06-01');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);

        $this->get("/reports/vendor-statement/pdf?contact_id={$contactA->id}&from=2026-06-01&to=2026-06-30")
            ->assertNotFound();
    }
}
