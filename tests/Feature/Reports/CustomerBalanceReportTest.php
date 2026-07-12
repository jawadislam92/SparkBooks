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
 * Customer Balance Summary + Detail (Phase 7 Task 6). The non-negotiable
 * gate (per the task brief): every customer's balance, AND the grand total,
 * must tie to the 1100 AR control account (LedgerBalances::cumulativeAsOf) —
 * base-currency-correct, including an FX invoice whose document-currency
 * total is nowhere near its base-currency total.
 */
class CustomerBalanceReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    // ---------------------------------------------------------------
    // Core tie-out: 3 customers, incl. one FX invoice, ties to 1100
    // in aggregate AND per-contact.
    // ---------------------------------------------------------------

    public function test_summary_ties_to_ar_control_account_in_aggregate_and_per_contact_including_fx_invoice(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);

        $alice = $this->contact($company, ['name' => 'Alice']);
        $bob = $this->contact($company, ['name' => 'Bob']);
        $carol = $this->contact($company, ['name' => 'Carol']);

        // Alice: one domestic invoice, unpaid. Balance = 10,000.
        $inv1 = $this->invoice($alice, 10000, '2026-06-01', '2026-06-15');
        $this->postInvoiceEntry($ar, $income, $inv1, 10000, '2026-06-01');

        // Bob: one domestic invoice, partially paid. Balance = 12,000.
        $inv2 = $this->invoice($bob, 20000, '2026-06-10', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $inv2, 20000, '2026-06-10');
        $this->payInvoice($inv2, $ar, $bankLedger, $bankRow, 8000, '2026-06-20');

        // Carol: one FX invoice — USD 1,000.00 (doc, 100000 minor units) ==
        // PKR 280,000.00 (base, 28,000,000 minor units) at fx_rate 280 —
        // partially paid USD 500.00 (50000) relieving PKR 140,000.00
        // (14,000,000) of base AR. Balance (BASE) = 28,000,000 - 14,000,000
        // = 14,000,000 minor units (PKR 140,000.00).
        // A naive DOCUMENT-currency sum (total=100000, balance=100000-50000
        // =50000 USD-cents) would be wrong by orders of magnitude.
        $inv3 = $this->fxInvoice($carol, 100000, 28000000, '280.00000000', '2026-06-05', '2026-07-05');
        $this->postFxInvoiceEntry($ar, $income, $inv3, 28000000, '2026-06-05');
        $this->payFxInvoice($inv3, $ar, $bankLedger, $bankRow, 50000, 14000000, '280.00000000', '2026-06-25');

        $report = $this->build()->customerBalanceSummary(CarbonImmutable::parse('2026-07-15'));

        $this->assertSame('ar', $report['direction']);
        $this->assertSame('1100', $report['control_account']['code']);
        $aliceRow = collect($report['contacts'])->firstWhere('contact_id', $alice->id);
        $bobRow = collect($report['contacts'])->firstWhere('contact_id', $bob->id);
        $carolRow = collect($report['contacts'])->firstWhere('contact_id', $carol->id);

        $this->assertSame(10000, $aliceRow['balance']);
        $this->assertSame(12000, $bobRow['balance']);
        $this->assertSame(14000000, $carolRow['balance'], 'FX invoice balance must be BASE-currency (PKR 140,000.00), not the document-currency (USD) remainder');

        $expectedGrandTotal = 10000 + 12000 + 14000000;
        $this->assertSame($expectedGrandTotal, $report['grand_total']);
        $this->assertSame($report['control_balance'], $report['grand_total']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertSame(0, $report['difference']);

        // Prove the fix: a naive DOCUMENT-currency sum of Carol's own
        // invoice.balance columns would have produced a totally different
        // (wrong) number than the base-correct 14,000,000 this report shows.
        $inv3->refresh();
        $naiveDocumentCurrencyBalance = $inv3->balance; // USD cents, never PKR
        $this->assertNotSame($naiveDocumentCurrencyBalance, $carolRow['balance']);

        $this->assertLedgerBalances();
    }

    public function test_summary_excludes_fully_paid_customer_and_grand_total_matches_control(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $invoice = $this->invoice($contact, 5000, '2026-06-01', '2026-06-10');
        $this->postInvoiceEntry($ar, $income, $invoice, 5000, '2026-06-01');
        $this->payInvoice($invoice, $ar, $bankLedger, $bankRow, 5000, '2026-06-15');

        $report = $this->build()->customerBalanceSummary(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
        $this->assertTrue($report['ties_to_control']);
    }

    public function test_summary_only_includes_current_companys_contacts(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 9000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 9000, '2026-01-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 4000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 4000, '2026-01-01');

        $report = $this->build()->customerBalanceSummary(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(4000, $report['grand_total']);
        $ids = collect($report['contacts'])->pluck('contact_id')->all();
        $this->assertContains($contactB->id, $ids);
        $this->assertNotContains($contactA->id, $ids);
    }

    // ---------------------------------------------------------------
    // Detail: running balance for one customer ends at the summary
    // balance for that same customer.
    // ---------------------------------------------------------------

    public function test_detail_running_balance_ends_at_the_summary_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $inv1 = $this->invoice($contact, 10000, '2026-06-01', '2026-06-15');
        $this->postInvoiceEntry($ar, $income, $inv1, 10000, '2026-06-01');

        $inv2 = $this->invoice($contact, 5000, '2026-06-05', '2026-06-20');
        $this->postInvoiceEntry($ar, $income, $inv2, 5000, '2026-06-05');

        $this->payInvoice($inv1, $ar, $bankLedger, $bankRow, 4000, '2026-06-10');

        $asOf = CarbonImmutable::parse('2026-07-01');
        $detail = $this->build()->customerBalanceDetail($asOf, $contact->id);
        $summary = $this->build()->customerBalanceSummary($asOf);

        $summaryRow = collect($summary['contacts'])->firstWhere('contact_id', $contact->id);

        $this->assertNotEmpty($detail['transactions']);
        $lastRow = end($detail['transactions']);
        $this->assertSame($lastRow['running_balance'], $detail['balance']);
        $this->assertSame($summaryRow['balance'], $detail['balance']);
        $this->assertSame(10000 + 5000 - 4000, $detail['balance']);

        // Running balance is monotonically re-derivable: recompute from
        // scratch and compare row by row.
        $running = 0;
        foreach ($detail['transactions'] as $row) {
            $running += $row['amount'];
            $this->assertSame($running, $row['running_balance']);
        }
    }

    public function test_detail_includes_opening_invoice_row(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $obe = $this->obeAccount($company);
        $contact = $this->contact($company);

        $openingInv = $this->openingInvoice($contact, 6000, '2026-01-01', '2026-01-31');
        $this->postOpeningRun($ar, $obe, [['doc' => $openingInv, 'amount' => 6000]], '2025-12-31', isDebit: true);

        $detail = $this->build()->customerBalanceDetail(CarbonImmutable::parse('2026-07-01'), $contact->id);

        $this->assertCount(1, $detail['transactions']);
        $this->assertSame('opening', $detail['transactions'][0]['type']);
        $this->assertSame(6000, $detail['transactions'][0]['amount']);
        $this->assertSame(6000, $detail['balance']);
    }

    public function test_detail_excludes_voided_invoice(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        $invoice = $this->invoice($contact, 7000, '2026-06-01', '2026-06-10');
        $entry = $this->postInvoiceEntry($ar, $income, $invoice, 7000, '2026-06-01');
        $this->voidInvoice($invoice, $entry, '2026-06-05');

        $detail = $this->build()->customerBalanceDetail(CarbonImmutable::parse('2026-07-01'), $contact->id);

        $this->assertSame([], $detail['transactions']);
        $this->assertSame(0, $detail['balance']);
    }
}
