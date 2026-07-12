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
 * Vendor Balance Summary + Detail — the AP-side mirror of
 * CustomerBalanceReportTest. Bills are base-currency-only in this app (spec
 * §17.1), so there's no FX case here; the tie-out gate is the 2000 AP
 * control account.
 */
class VendorBalanceReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    public function test_summary_ties_to_ap_control_account_in_aggregate_and_per_contact(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);

        $vendorA = $this->contact($company, ['name' => 'Vendor A']);
        $vendorB = $this->contact($company, ['name' => 'Vendor B']);

        $bill1 = $this->bill($vendorA, 15000, '2026-06-01', '2026-06-20');
        $this->postBillEntry($ap, $expense, $bill1, 15000, '2026-06-01');

        $bill2 = $this->bill($vendorB, 8000, '2026-06-05', '2026-06-25');
        $this->postBillEntry($ap, $expense, $bill2, 8000, '2026-06-05');
        $this->payBill($bill2, $ap, $bankLedger, $bankRow, 3000, '2026-06-10');

        $report = $this->build()->vendorBalanceSummary(CarbonImmutable::parse('2026-07-15'));

        $this->assertSame('ap', $report['direction']);
        $rowA = collect($report['contacts'])->firstWhere('contact_id', $vendorA->id);
        $rowB = collect($report['contacts'])->firstWhere('contact_id', $vendorB->id);

        $this->assertSame(15000, $rowA['balance']);
        $this->assertSame(5000, $rowB['balance']);
        $this->assertSame(20000, $report['grand_total']);
        $this->assertSame($report['control_balance'], $report['grand_total']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertSame(0, $report['difference']);
        $this->assertLedgerBalances();
    }

    public function test_summary_excludes_fully_paid_vendor(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 5000, '2026-06-01', '2026-06-10');
        $this->postBillEntry($ap, $expense, $bill, 5000, '2026-06-01');
        $this->payBill($bill, $ap, $bankLedger, $bankRow, 5000, '2026-06-15');

        $report = $this->build()->vendorBalanceSummary(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
    }

    public function test_summary_only_includes_current_companys_contacts(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $apA = $this->apAccount($companyA);
        $expenseA = $this->expenseAccount($companyA);
        $contactA = $this->contact($companyA);
        $billA = $this->bill($contactA, 9000, '2026-01-01', '2026-06-01');
        $this->postBillEntry($apA, $expenseA, $billA, 9000, '2026-01-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $apB = $this->apAccount($companyB);
        $expenseB = $this->expenseAccount($companyB);
        $contactB = $this->contact($companyB);
        $billB = $this->bill($contactB, 4000, '2026-01-01', '2026-06-01');
        $this->postBillEntry($apB, $expenseB, $billB, 4000, '2026-01-01');

        $report = $this->build()->vendorBalanceSummary(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(4000, $report['grand_total']);
        $ids = collect($report['contacts'])->pluck('contact_id')->all();
        $this->assertContains($contactB->id, $ids);
        $this->assertNotContains($contactA->id, $ids);
    }

    public function test_detail_running_balance_ends_at_the_summary_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $bill1 = $this->bill($contact, 10000, '2026-06-01', '2026-06-15');
        $this->postBillEntry($ap, $expense, $bill1, 10000, '2026-06-01');

        $bill2 = $this->bill($contact, 5000, '2026-06-05', '2026-06-20');
        $this->postBillEntry($ap, $expense, $bill2, 5000, '2026-06-05');

        $this->payBill($bill1, $ap, $bankLedger, $bankRow, 4000, '2026-06-10');

        $asOf = CarbonImmutable::parse('2026-07-01');
        $detail = $this->build()->vendorBalanceDetail($asOf, $contact->id);
        $summary = $this->build()->vendorBalanceSummary($asOf);
        $summaryRow = collect($summary['contacts'])->firstWhere('contact_id', $contact->id);

        $this->assertNotEmpty($detail['transactions']);
        $lastRow = end($detail['transactions']);
        $this->assertSame($lastRow['running_balance'], $detail['balance']);
        $this->assertSame($summaryRow['balance'], $detail['balance']);
        $this->assertSame(10000 + 5000 - 4000, $detail['balance']);

        $running = 0;
        foreach ($detail['transactions'] as $row) {
            $running += $row['amount'];
            $this->assertSame($running, $row['running_balance']);
        }
    }

    public function test_detail_excludes_voided_bill(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 8000, '2026-06-01', '2026-06-10');
        $entry = $this->postBillEntry($ap, $expense, $bill, 8000, '2026-06-01');
        $this->voidBill($bill, $entry, '2026-06-05');

        $detail = $this->build()->vendorBalanceDetail(CarbonImmutable::parse('2026-07-01'), $contact->id);

        $this->assertSame([], $detail['transactions']);
        $this->assertSame(0, $detail['balance']);
    }
}
