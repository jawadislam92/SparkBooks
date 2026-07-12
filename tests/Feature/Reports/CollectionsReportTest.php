<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Services\Reports\ContactLedgerReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

/**
 * Collections report (Phase 7 Task 6): overdue AR grouped by customer, aged
 * past each invoice's own due date, with days-overdue + contact phone/email
 * for chasing. Only strictly past-due invoices appear (due exactly today or
 * later is never "overdue" — same predicate as AgingReport's Current bucket
 * / DashboardController's isOverdue).
 */
class CollectionsReportTest extends TestCase
{
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    public function test_only_past_due_invoices_appear_with_correct_days_overdue(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company, ['name' => 'Overdue Co', 'email' => 'ap@overdue.test', 'phone' => '555-1234']);

        $asOf = CarbonImmutable::parse('2026-07-31');

        // Current (due exactly today) — must NOT appear.
        $currentInvoice = $this->invoice($contact, 1000, '2026-07-01', '2026-07-31');
        $this->postInvoiceEntry($ar, $income, $currentInvoice, 1000, '2026-07-01');

        // Not yet due — must NOT appear.
        $futureInvoice = $this->invoice($contact, 2000, '2026-07-01', '2026-08-15');
        $this->postInvoiceEntry($ar, $income, $futureInvoice, 2000, '2026-07-01');

        // 10 days overdue.
        $overdueInvoice = $this->invoice($contact, 3000, '2026-06-01', '2026-07-21');
        $this->postInvoiceEntry($ar, $income, $overdueInvoice, 3000, '2026-06-01');

        // Null due date — never overdue.
        $noDueDateInvoice = $this->invoice($contact, 500, '2026-01-01', null);
        $this->postInvoiceEntry($ar, $income, $noDueDateInvoice, 500, '2026-01-01');

        $report = $this->build()->collections($asOf);

        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertNotNull($row);
        $this->assertSame('Overdue Co', $row['name']);
        $this->assertSame('ap@overdue.test', $row['email']);
        $this->assertSame('555-1234', $row['phone']);

        $docIds = collect($row['documents'])->pluck('doc_id')->all();
        $this->assertContains($overdueInvoice->id, $docIds);
        $this->assertNotContains($currentInvoice->id, $docIds);
        $this->assertNotContains($futureInvoice->id, $docIds);
        $this->assertNotContains($noDueDateInvoice->id, $docIds);

        $overdueRow = collect($row['documents'])->firstWhere('doc_id', $overdueInvoice->id);
        $this->assertSame(10, $overdueRow['days_overdue']);
        $this->assertSame(3000, $overdueRow['outstanding']);
        $this->assertSame(3000, $row['total']);
        $this->assertSame(3000, $report['grand_total']);
    }

    public function test_due_exactly_today_is_not_overdue(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        $asOf = CarbonImmutable::parse('2026-07-31');
        $invoice = $this->invoice($contact, 1000, '2026-07-01', '2026-07-31');
        $this->postInvoiceEntry($ar, $income, $invoice, 1000, '2026-07-01');

        $report = $this->build()->collections($asOf);

        $this->assertSame([], $report['contacts']);
        $this->assertSame(0, $report['grand_total']);
    }

    public function test_scoped_to_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 3000, '2026-01-01', '2026-02-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 3000, '2026-01-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 1000, '2026-01-01', '2026-02-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 1000, '2026-01-01');

        $report = $this->build()->collections(CarbonImmutable::parse('2026-07-15'));

        $this->assertSame(1000, $report['grand_total']);
        $ids = collect($report['contacts'])->pluck('contact_id')->all();
        $this->assertContains($contactB->id, $ids);
        $this->assertNotContains($contactA->id, $ids);
    }
}
