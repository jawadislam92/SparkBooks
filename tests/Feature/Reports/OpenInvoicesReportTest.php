<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Services\Reports\ContactLedgerReport;
use App\Services\Reports\LedgerBalances;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsLedgerBalanced;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

/**
 * Open Invoices (Phase 7 Task 6): every unpaid/partly-paid invoice, each
 * with its original + outstanding (base-correct) amount; the total
 * outstanding must tie to the 1100 AR control account.
 */
class OpenInvoicesReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    private function build(): ContactLedgerReport
    {
        return app(ContactLedgerReport::class);
    }

    public function test_fully_paid_invoice_is_excluded_and_partly_paid_shows_correct_outstanding(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        // Fully paid — must not appear.
        $paidInvoice = $this->invoice($contact, 5000, '2026-06-01', '2026-06-10');
        $this->postInvoiceEntry($ar, $income, $paidInvoice, 5000, '2026-06-01');
        $this->payInvoice($paidInvoice, $ar, $bankLedger, $bankRow, 5000, '2026-06-15');

        // Unpaid.
        $unpaidInvoice = $this->invoice($contact, 8000, '2026-06-05', '2026-06-20');
        $this->postInvoiceEntry($ar, $income, $unpaidInvoice, 8000, '2026-06-05');

        // Partly paid.
        $partialInvoice = $this->invoice($contact, 10000, '2026-06-10', '2026-06-25');
        $this->postInvoiceEntry($ar, $income, $partialInvoice, 10000, '2026-06-10');
        $this->payInvoice($partialInvoice, $ar, $bankLedger, $bankRow, 6000, '2026-06-18');

        $report = $this->build()->openInvoices(CarbonImmutable::parse('2026-07-01'));

        $ids = collect($report['documents'])->pluck('doc_id')->all();
        $this->assertNotContains($paidInvoice->id, $ids);
        $this->assertContains($unpaidInvoice->id, $ids);
        $this->assertContains($partialInvoice->id, $ids);

        $unpaidRow = collect($report['documents'])->firstWhere('doc_id', $unpaidInvoice->id);
        $this->assertSame(8000, $unpaidRow['original']);
        $this->assertSame(8000, $unpaidRow['outstanding']);

        $partialRow = collect($report['documents'])->firstWhere('doc_id', $partialInvoice->id);
        $this->assertSame(10000, $partialRow['original']);
        $this->assertSame(4000, $partialRow['outstanding']);

        $this->assertSame(8000 + 4000, $report['total_outstanding']);
        $this->assertSame($report['control_balance'], $report['total_outstanding']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertLedgerBalances();
    }

    public function test_total_outstanding_ties_to_ar_control_account_with_fx_invoice(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $fx = $this->fxInvoice($contact, 100000, 28000000, '280.00000000', '2026-06-01', '2026-07-01');
        $this->postFxInvoiceEntry($ar, $income, $fx, 28000000, '2026-06-01');
        $this->payFxInvoice($fx, $ar, $bankLedger, $bankRow, 50000, 14000000, '280.00000000', '2026-06-10');

        $report = $this->build()->openInvoices(CarbonImmutable::parse('2026-07-15'));

        $row = collect($report['documents'])->firstWhere('doc_id', $fx->id);
        $this->assertSame(28000000, $row['original']);
        $this->assertSame(14000000, $row['outstanding']);
        $this->assertSame(14000000, $report['total_outstanding']);
        $this->assertSame($report['control_balance'], $report['total_outstanding']);
    }

    /**
     * Review follow-up regression (Phase 7 Task 6 review, gap #1): a voided
     * invoice's own entry nets to exactly zero (reversal cancels the
     * original), so ContactLedgerReport::ledger() drops it from `documents`
     * entirely — see that method's "own entry nets to zero => voided" branch.
     * Confirms it disappears from Open Invoices AND that total_outstanding
     * still ties to an INDEPENDENTLY computed 1100 AR control balance
     * (LedgerBalances::cumulativeAsOf), not just to the report's own
     * control_balance field (which is built from the same reconstruction and
     * so could never disagree with itself even if both were wrong).
     */
    public function test_voided_invoice_is_excluded_and_total_still_ties_to_ar_control(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        $liveInvoice = $this->invoice($contact, 6000, '2026-06-01', '2026-06-20');
        $this->postInvoiceEntry($ar, $income, $liveInvoice, 6000, '2026-06-01');

        $voidedInvoice = $this->invoice($contact, 9000, '2026-06-05', '2026-06-25');
        $entry = $this->postInvoiceEntry($ar, $income, $voidedInvoice, 9000, '2026-06-05');
        $this->voidInvoice($voidedInvoice, $entry, '2026-06-10');

        $asOf = CarbonImmutable::parse('2026-07-01');
        $report = $this->build()->openInvoices($asOf);

        $ids = collect($report['documents'])->pluck('doc_id')->all();
        $this->assertNotContains($voidedInvoice->id, $ids);
        $this->assertContains($liveInvoice->id, $ids);

        $this->assertSame(6000, $report['total_outstanding']);

        // Independent oracle: AR is a debit-balance control account, so its
        // control balance IS the raw debit-minus-credit net (no sign flip),
        // mirroring ContactLedgerReport::ledger()'s own 'ar' branch.
        $arControl = app(LedgerBalances::class)->cumulativeAsOf($asOf, [$ar->id])[$ar->id]['net'];
        $this->assertSame(6000, $arControl);
        $this->assertSame($arControl, $report['total_outstanding']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertLedgerBalances();
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
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 1000, '2026-06-01', '2026-07-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 1000, '2026-06-01');

        $report = $this->build()->openInvoices(CarbonImmutable::parse('2026-07-15'));

        $ids = collect($report['documents'])->pluck('doc_id')->all();
        $this->assertContains($invoiceB->id, $ids);
        $this->assertNotContains($invoiceA->id, $ids);
    }
}
