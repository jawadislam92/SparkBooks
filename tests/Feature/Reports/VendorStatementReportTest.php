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
 * Vendor Statement — the AP-side mirror of CustomerStatementReportTest.
 * Bills are base-currency-only (spec §17.1), so no FX case here.
 */
class VendorStatementReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    public function test_opening_plus_period_activity_equals_closing_which_ties_to_control(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $before = $this->bill($contact, 10000, '2026-01-01', '2026-01-15');
        $this->postBillEntry($ap, $expense, $before, 10000, '2026-01-01');
        $this->payBill($before, $ap, $bankLedger, $bankRow, 3000, '2026-01-10');

        $inPeriod = $this->bill($contact, 5000, '2026-06-05', '2026-07-05');
        $this->postBillEntry($ap, $expense, $inPeriod, 5000, '2026-06-05');
        $this->payBill($inPeriod, $ap, $bankLedger, $bankRow, 2000, '2026-06-20');

        $from = CarbonImmutable::parse('2026-06-01');
        $to = CarbonImmutable::parse('2026-06-30');

        $statement = $this->build()->vendorStatement($from, $to, $contact->id);

        $this->assertSame(7000, $statement['opening_balance']);
        $this->assertCount(2, $statement['transactions']);
        $this->assertSame(7000 + 5000 - 2000, $statement['closing_balance']);

        $summary = $this->build()->vendorBalanceSummary($to);
        $summaryRow = collect($summary['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertSame($summaryRow['balance'], $statement['closing_balance']);

        $running = $statement['opening_balance'];
        foreach ($statement['transactions'] as $row) {
            $running += $row['amount'];
            $this->assertSame($running, $row['running_balance']);
        }

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

        $statement = $this->build()->vendorStatement(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-06-30'),
            $contactA->id,
        );

        $this->assertSame(0, $statement['opening_balance']);
        $this->assertSame([], $statement['transactions']);
        $this->assertSame(0, $statement['closing_balance']);
    }
}
