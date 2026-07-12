<?php

namespace Tests\Feature\Reports;

use App\Enums\Role;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

class CustomerStatementTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    public function test_staff_forbidden_from_viewing_statement(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/customer-statement')->assertForbidden();
    }

    public function test_report_is_null_until_a_contact_is_chosen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/customer-statement')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CustomerStatement')
            ->where('report', null)
        );
    }

    public function test_owner_can_view_statement_for_a_chosen_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);
        $invoice = $this->invoice($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 5000, '2026-06-01');

        $response = $this->get("/reports/customer-statement?contact_id={$contact->id}&from=2026-06-01&to=2026-06-30");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/CustomerStatement')
            ->where('report.closing_balance', 5000)
        );
    }

    public function test_pdf_route_renders_successfully(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);
        $invoice = $this->invoice($contact, 5000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $invoice, 5000, '2026-06-01');

        $response = $this->get("/reports/customer-statement/pdf?contact_id={$contact->id}&from=2026-06-01&to=2026-06-30");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_route_requires_contact_id(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/customer-statement/pdf')->assertSessionHasErrors('contact_id');
    }

    public function test_statement_scoped_to_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 9000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 9000, '2026-06-01');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);

        // Contact A's id doesn't exist in company B's tenant scope -> 404.
        $this->get("/reports/customer-statement/pdf?contact_id={$contactA->id}&from=2026-06-01&to=2026-06-30")
            ->assertNotFound();
    }
}
