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
 * Customer Statement (Phase 7 Task 6) — the client-facing artifact: opening
 * balance as-of the period start, every invoice/payment IN the period with a
 * running balance, and a closing balance that must equal the customer's real
 * AR balance at period end (tied to the 1100 control account) — including
 * when the customer has an FX invoice, whose contribution must be its BASE
 * value, not its document-currency value.
 */
class CustomerStatementReportTest extends TestCase
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
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        // Before the period: invoice 10,000, paid 3,000 -> opening = 7,000.
        $before = $this->invoice($contact, 10000, '2026-01-01', '2026-01-15');
        $this->postInvoiceEntry($ar, $income, $before, 10000, '2026-01-01');
        $this->payInvoice($before, $ar, $bankLedger, $bankRow, 3000, '2026-01-10');

        // Within the period (Jun 1 - Jun 30): new invoice + a payment.
        $inPeriod = $this->invoice($contact, 5000, '2026-06-05', '2026-07-05');
        $this->postInvoiceEntry($ar, $income, $inPeriod, 5000, '2026-06-05');
        $this->payInvoice($inPeriod, $ar, $bankLedger, $bankRow, 2000, '2026-06-20');

        $from = CarbonImmutable::parse('2026-06-01');
        $to = CarbonImmutable::parse('2026-06-30');

        $statement = $this->build()->customerStatement($from, $to, $contact->id);

        $this->assertSame(7000, $statement['opening_balance']);
        $this->assertCount(2, $statement['transactions']);
        $this->assertSame(7000 + 5000 - 2000, $statement['closing_balance']);

        // Closing must tie to this contact's real balance at period end —
        // computed independently via the summary engine as-of $to.
        $summary = $this->build()->customerBalanceSummary($to);
        $summaryRow = collect($summary['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertSame($summaryRow['balance'], $statement['closing_balance']);

        // Running balance recomputes correctly row by row from opening.
        $running = $statement['opening_balance'];
        foreach ($statement['transactions'] as $row) {
            $running += $row['amount'];
            $this->assertSame($running, $row['running_balance']);
        }
        $this->assertSame($running, $statement['closing_balance']);

        $this->assertLedgerBalances();
    }

    public function test_fx_invoice_contributes_its_base_value_to_the_closing_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        // FX invoice: USD 1,000.00 (doc, 100000) == PKR 280,000.00 (base,
        // 28000000) at fx_rate 280 -- posted, then partially paid within the
        // statement period.
        $fx = $this->fxInvoice($contact, 100000, 28000000, '280.00000000', '2026-06-01', '2026-07-01');
        $this->postFxInvoiceEntry($ar, $income, $fx, 28000000, '2026-06-05');
        $this->payFxInvoice($fx, $ar, $bankLedger, $bankRow, 50000, 14000000, '280.00000000', '2026-06-15');

        $from = CarbonImmutable::parse('2026-06-01');
        $to = CarbonImmutable::parse('2026-06-30');

        $statement = $this->build()->customerStatement($from, $to, $contact->id);

        $this->assertSame(0, $statement['opening_balance']);
        $this->assertSame(28000000 - 14000000, $statement['closing_balance']);

        $invoiceRow = collect($statement['transactions'])->firstWhere('type', 'invoice');
        $this->assertNotNull($invoiceRow);
        $this->assertSame(28000000, $invoiceRow['amount'], 'the invoice line must carry its BASE (PKR) value, not its document-currency (USD) value');

        $paymentRow = collect($statement['transactions'])->firstWhere('type', 'payment');
        $this->assertNotNull($paymentRow);
        $this->assertSame(-14000000, $paymentRow['amount']);

        // Prove the fix: the document-currency numbers (100000 / -50000)
        // would have produced an entirely different, wrong closing balance.
        $wrongDocumentCurrencyClosing = 100000 - 50000;
        $this->assertNotSame($wrongDocumentCurrencyClosing, $statement['closing_balance']);

        // Ties to the contact's real balance at period end.
        $summary = $this->build()->customerBalanceSummary($to);
        $summaryRow = collect($summary['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertSame($summaryRow['balance'], $statement['closing_balance']);
    }

    public function test_scoped_to_current_company(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 3000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 3000, '2026-06-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);

        $statement = $this->build()->customerStatement(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-06-30'),
            $contactA->id,
        );

        // Contact A belongs to a different company: no activity is visible
        // from company B's context.
        $this->assertSame(0, $statement['opening_balance']);
        $this->assertSame([], $statement['transactions']);
        $this->assertSame(0, $statement['closing_balance']);
    }
}
