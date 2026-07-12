<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Services\Reports\ContactLedgerReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsLedgerBalanced;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

/**
 * Unpaid Bills — the AP-side mirror of OpenInvoicesReportTest. Ties to the
 * 2000 AP control account.
 */
class UnpaidBillsReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    public function test_fully_paid_bill_is_excluded_and_partly_paid_shows_correct_outstanding(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $paidBill = $this->bill($contact, 5000, '2026-06-01', '2026-06-10');
        $this->postBillEntry($ap, $expense, $paidBill, 5000, '2026-06-01');
        $this->payBill($paidBill, $ap, $bankLedger, $bankRow, 5000, '2026-06-15');

        $unpaidBill = $this->bill($contact, 8000, '2026-06-05', '2026-06-20');
        $this->postBillEntry($ap, $expense, $unpaidBill, 8000, '2026-06-05');

        $partialBill = $this->bill($contact, 10000, '2026-06-10', '2026-06-25');
        $this->postBillEntry($ap, $expense, $partialBill, 10000, '2026-06-10');
        $this->payBill($partialBill, $ap, $bankLedger, $bankRow, 6000, '2026-06-18');

        $report = $this->build()->unpaidBills(CarbonImmutable::parse('2026-07-01'));

        $ids = collect($report['documents'])->pluck('doc_id')->all();
        $this->assertNotContains($paidBill->id, $ids);
        $this->assertContains($unpaidBill->id, $ids);
        $this->assertContains($partialBill->id, $ids);

        $unpaidRow = collect($report['documents'])->firstWhere('doc_id', $unpaidBill->id);
        $this->assertSame(8000, $unpaidRow['original']);
        $this->assertSame(8000, $unpaidRow['outstanding']);

        $partialRow = collect($report['documents'])->firstWhere('doc_id', $partialBill->id);
        $this->assertSame(10000, $partialRow['original']);
        $this->assertSame(4000, $partialRow['outstanding']);

        $this->assertSame(8000 + 4000, $report['total_outstanding']);
        $this->assertSame($report['control_balance'], $report['total_outstanding']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertLedgerBalances();
    }

    public function test_scoped_to_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $apA = $this->apAccount($companyA);
        $expenseA = $this->expenseAccount($companyA);
        $contactA = $this->contact($companyA);
        $billA = $this->bill($contactA, 3000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($apA, $expenseA, $billA, 3000, '2026-06-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $apB = $this->apAccount($companyB);
        $expenseB = $this->expenseAccount($companyB);
        $contactB = $this->contact($companyB);
        $billB = $this->bill($contactB, 1000, '2026-06-01', '2026-07-01');
        $this->postBillEntry($apB, $expenseB, $billB, 1000, '2026-06-01');

        $report = $this->build()->unpaidBills(CarbonImmutable::parse('2026-07-15'));

        $ids = collect($report['documents'])->pluck('doc_id')->all();
        $this->assertContains($billB->id, $ids);
        $this->assertNotContains($billA->id, $ids);
    }
}
