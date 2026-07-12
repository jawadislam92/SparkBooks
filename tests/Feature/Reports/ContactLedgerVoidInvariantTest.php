<?php

namespace Tests\Feature\Reports;

use App\Enums\BillStatus;
use App\Enums\EntryRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\Documents\BillHasLivePayments;
use App\Exceptions\Invoicing\InvoiceHasLivePayments;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\BillService;
use App\Services\InvoiceService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsLedgerBalanced;
use Tests\Support\ContactLedgerTestHelpers;
use Tests\TestCase;

/**
 * Review follow-up regression (Phase 7 Task 6 review, gap #2): pins down the
 * upstream guard that ContactLedgerReport::ledger() silently depends on.
 *
 * That method treats a document whose own entry nets to exactly zero as
 * "voided" and drops it — document AND every payment against it — from
 * Open Invoices/Unpaid Bills/Collections/Balance Detail (see its class
 * docblock's "own entry nets to zero => voided => drop document" branch).
 * That's only safe because InvoiceService::void()/BillService::void()
 * REFUSE to void a document while a live (un-reversed) payment remains
 * against it (guardVoid) — payments must be reversed one-by-one FIRST via
 * InvoicePaymentService::voidPayment/BillPaymentService::void, which is a
 * conscious, separate user action, before the document itself can go. If
 * that guard were ever weakened or bypassed, a document with a real, live
 * payment could be voided while the payment stayed live — reversing only
 * the document's own entry to zero net — and the report would then
 * silently drop the entire document, live payment included, understating
 * what's actually owed/owing.
 *
 * This test doesn't exercise ContactLedgerReport itself (that's the three
 * sibling void-exclusion tests in this directory); it exercises the real
 * InvoiceService::void()/BillService::void() guard those tests — and the
 * report's own safety — depend on, using the SAME
 * ContactLedgerTestHelpers-posted fixtures (real LedgerService-posted
 * entries) those tests already use, per the reviewer's exact instruction to
 * reuse this scaffolding rather than inventing new fixtures.
 */
class ContactLedgerVoidInvariantTest extends TestCase
{
    use AssertsLedgerBalanced;
    use ContactLedgerTestHelpers;
    use RefreshDatabase;

    public function test_invoice_void_is_refused_while_a_live_payment_remains(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $invoice = $this->invoice($contact, 8000, '2026-06-01', '2026-06-20');
        $this->postInvoiceEntry($ar, $income, $invoice, 8000, '2026-06-01');
        $this->payInvoice($invoice, $ar, $bankLedger, $bankRow, 3000, '2026-06-10');

        try {
            app(InvoiceService::class)->void(
                (string) Str::uuid(),
                $invoice,
                '2026-07-01',
                'Attempted void with a live payment still outstanding'
            );
            $this->fail('Expected InvoiceHasLivePayments to be thrown; the invoice was voided instead.');
        } catch (InvoiceHasLivePayments) {
            // Expected: guardVoid refuses while the payment is still live.
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->voided_at);

        // No reversal entry was posted against the invoice's own entry.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)
            ->count());

        $this->assertLedgerBalances();
    }

    public function test_bill_void_is_refused_while_a_live_payment_remains(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 8000, '2026-06-01', '2026-06-20');
        $this->postBillEntry($ap, $expense, $bill, 8000, '2026-06-01');
        $this->payBill($bill, $ap, $bankLedger, $bankRow, 3000, '2026-06-10');

        try {
            app(BillService::class)->void((string) Str::uuid(), $bill);
            $this->fail('Expected BillHasLivePayments to be thrown; the bill was voided instead.');
        } catch (BillHasLivePayments) {
            // Expected: guardVoid refuses while the payment is still live.
        }

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertNull($bill->voided_at);

        // No reversal entry was posted against the bill's own entry.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $bill->id)
            ->where('entry_role', EntryRole::Reversal->value)
            ->count());

        $this->assertLedgerBalances();
    }
}
