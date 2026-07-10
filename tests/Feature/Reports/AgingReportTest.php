<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Services\Reports\AgingReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * §12.9 tie-out gate under test throughout: AR aging's grand_total must
 * equal 1100's control balance; AP aging's grand_total must equal 2000's.
 * See AgingReport's own class docblock for the full reasoning behind the
 * opening-document exception this suite exercises directly (multi-opening-
 * document-per-contact, the fbbe763 case).
 */
class AgingReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function arAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);
    }

    private function apAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
        ]);
    }

    private function incomeAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);
    }

    private function expenseAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);
    }

    private function bankLedgerAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);
    }

    private function obeAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '3100',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
            'is_system' => true,
        ]);
    }

    private function bankAccountRow(Account $ledgerAccount): BankAccount
    {
        return BankAccount::factory()->create(['ledger_account_id' => $ledgerAccount->id]);
    }

    private function contact(Company $company): Contact
    {
        return Contact::factory()->create(['company_id' => $company->id]);
    }

    // ---------------------------------------------------------------
    // Document + ledger builders — mirror the real posting shapes
    // (InvoiceService/BillService/InvoicePaymentService/BillPaymentService/
    // OpeningBalanceService) directly via LedgerService, the same lighter
    // style BalanceSheetReportTest uses for report-service-level tests.
    // ---------------------------------------------------------------

    private function invoice(Contact $contact, int $amount, string $issueDate, ?string $dueDate, string $status = 'sent'): Invoice
    {
        return Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'base_subtotal' => $amount,
            'base_tax_total' => 0,
            'base_total' => $amount,
            'is_opening' => false,
            'sent_at' => now(),
        ]);
    }

    private function bill(Contact $contact, int $amount, string $billDate, ?string $dueDate, string $status = 'received'): Bill
    {
        return Bill::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'is_opening' => false,
            'received_at' => now(),
        ]);
    }

    /** Dr AR, Cr income — mirrors InvoiceService::send()'s P1. */
    private function postInvoiceEntry(Account $ar, Account $income, Invoice $invoice, int $amount, string $date): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Invoice',
            sourceType: 'invoice',
            sourceId: $invoice->id,
            lines: [
                new LineDraft(accountId: $ar->id, debit: $amount, credit: 0, contactId: $invoice->contact_id),
                new LineDraft(accountId: $income->id, debit: 0, credit: $amount),
            ],
        )));
    }

    /** Dr expense, Cr AP — mirrors BillService::receive()'s P6. */
    private function postBillEntry(Account $ap, Account $expense, Bill $bill, int $amount, string $date): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Bill',
            sourceType: 'bill',
            sourceId: $bill->id,
            lines: [
                new LineDraft(accountId: $expense->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $ap->id, debit: 0, credit: $amount, contactId: $bill->contact_id),
            ],
        )));
    }

    /** Dr bank, Cr AR — mirrors InvoicePaymentService's P2. */
    private function payInvoice(Invoice $invoice, Account $ar, Account $bankLedger, BankAccount $bankRow, int $amount, string $date): InvoicePayment
    {
        $payment = InvoicePayment::factory()->create([
            'invoice_id' => $invoice->id,
            'payment_date' => $date,
            'amount' => $amount,
            'base_amount' => $amount,
            'base_ar_relief' => $amount,
            'bank_account_id' => $bankRow->id,
            'fx_rate' => null,
            'status' => 'posted',
        ]);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Invoice payment',
            sourceType: 'invoice_payment',
            sourceId: $payment->id,
            lines: [
                new LineDraft(accountId: $bankLedger->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $ar->id, debit: 0, credit: $amount, contactId: $invoice->contact_id),
            ],
        )));

        return $payment;
    }

    /** Dr AP, Cr bank — mirrors BillPaymentService's P7. */
    private function payBill(Bill $bill, Account $ap, Account $bankLedger, BankAccount $bankRow, int $amount, string $date): BillPayment
    {
        $payment = BillPayment::factory()->create([
            'bill_id' => $bill->id,
            'payment_date' => $date,
            'amount' => $amount,
            'bank_account_id' => $bankRow->id,
            'status' => 'posted',
        ]);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Bill payment',
            sourceType: 'bill_payment',
            sourceId: $payment->id,
            lines: [
                new LineDraft(accountId: $ap->id, debit: $amount, credit: 0, contactId: $bill->contact_id),
                new LineDraft(accountId: $bankLedger->id, debit: 0, credit: $amount),
            ],
        )));

        return $payment;
    }

    private function voidInvoice(Invoice $invoice, JournalEntry $entry, string $date): void
    {
        DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse($date), 'Void'));
        $invoice->forceFill(['status' => InvoiceStatus::Void->value, 'voided_at' => now()])->save();
    }

    private function voidBill(Bill $bill, JournalEntry $entry, string $date): void
    {
        DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse($date), 'Void'));
        $bill->forceFill(['status' => BillStatus::Void->value, 'voided_at' => now()])->save();
    }

    /**
     * An is_opening Invoice/Bill row PLUS a run-shaped ledger entry: one
     * control-account line per document (all sharing contactId), plus an
     * OBE plug — mirrors OpeningBalanceService::buildLinesAndDocuments()'s
     * actual shape, so a same-contact, multi-document run genuinely shares
     * one ledger entry the way production data would.
     *
     * @param  list<array{doc: Invoice|Bill, amount:int}>  $docs
     */
    private function postOpeningRun(Account $controlAccount, Account $obe, array $docs, string $entryDate, int $runId, bool $isDebit): void
    {
        $lines = [];
        $total = 0;
        foreach ($docs as $entry) {
            $contactId = $entry['doc']->contact_id;
            $amount = $entry['amount'];
            $lines[] = $isDebit
                ? new LineDraft(accountId: $controlAccount->id, debit: $amount, credit: 0, contactId: $contactId)
                : new LineDraft(accountId: $controlAccount->id, debit: 0, credit: $amount, contactId: $contactId);
            $total += $amount;
        }
        $lines[] = $isDebit
            ? new LineDraft(accountId: $obe->id, debit: 0, credit: $total)
            : new LineDraft(accountId: $obe->id, debit: $total, credit: 0);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($entryDate),
            memo: 'Opening balances',
            sourceType: 'opening_balance_run',
            sourceId: $runId,
            lines: $lines,
        )));
    }

    private function openingInvoice(Contact $contact, int $amount, string $originalDate, string $dueDate): Invoice
    {
        return Invoice::factory()->create([
            'contact_id' => $contact->id,
            'number' => 'OB-'.fake()->unique()->numerify('####'),
            'status' => InvoiceStatus::Sent->value,
            'issue_date' => $originalDate,
            'due_date' => $dueDate,
            'currency_code' => 'PKR',
            'fx_rate' => 1,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'base_subtotal' => $amount,
            'base_tax_total' => 0,
            'base_total' => $amount,
            'is_opening' => true,
            'sent_at' => now(),
        ]);
    }

    private function openingBill(Contact $contact, int $amount, string $originalDate, string $dueDate): Bill
    {
        return Bill::factory()->create([
            'contact_id' => $contact->id,
            'status' => BillStatus::Received->value,
            'bill_date' => $originalDate,
            'due_date' => $dueDate,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'is_opening' => true,
            'received_at' => now(),
        ]);
    }

    private function build(): AgingReport
    {
        return app(AgingReport::class);
    }

    // ---------------------------------------------------------------
    // §12.9: AR grand total ties to 1100 control balance, incl. opening.
    // ---------------------------------------------------------------

    public function test_ar_grand_total_ties_to_1100_control_balance_including_opening_receivables(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $obe = $this->obeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);

        $alice = $this->contact($company);
        $bob = $this->contact($company);

        // Normal invoice, unpaid.
        $inv1 = $this->invoice($alice, 10000, '2026-06-01', '2026-06-15');
        $this->postInvoiceEntry($ar, $income, $inv1, 10000, '2026-06-01');

        // Normal invoice, partially paid.
        $inv2 = $this->invoice($bob, 20000, '2026-06-10', '2026-07-01');
        $this->postInvoiceEntry($ar, $income, $inv2, 20000, '2026-06-10');
        $this->payInvoice($inv2, $ar, $bankLedger, $bankRow, 8000, '2026-06-20');

        // Opening receivable for Alice.
        $openingInv = $this->openingInvoice($alice, 5000, '2026-01-01', '2026-01-31');
        $this->postOpeningRun($ar, $obe, [['doc' => $openingInv, 'amount' => 5000]], '2025-12-31', 1, isDebit: true);

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-15'));

        $this->assertSame($report['control_balance'], $report['grand_total']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertSame(0, $report['difference']);
        $this->assertSame(10000 + 12000 + 5000, $report['grand_total']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // §12.9: AP grand total ties to 2000 control balance, incl. opening +
    // the fbbe763 multi-opening-bill-per-vendor case.
    // ---------------------------------------------------------------

    public function test_ap_grand_total_ties_to_2000_control_balance_including_opening_payables_and_multi_bill_vendor(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $obe = $this->obeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);

        $vendor = $this->contact($company);
        $otherVendor = $this->contact($company);

        // Normal bill, unpaid.
        $bill1 = $this->bill($otherVendor, 15000, '2026-06-01', '2026-06-20');
        $this->postBillEntry($ap, $expense, $bill1, 15000, '2026-06-01');

        // The fbbe763 case: ONE vendor, TWO opening bills in the SAME run,
        // sharing one ledger entry — must sum both, not drop one.
        $openingBillA = $this->openingBill($vendor, 3000, '2026-01-01', '2026-02-01');
        $openingBillB = $this->openingBill($vendor, 7000, '2026-01-05', '2026-03-01');
        $this->postOpeningRun($ap, $obe, [
            ['doc' => $openingBillA, 'amount' => 3000],
            ['doc' => $openingBillB, 'amount' => 7000],
        ], '2025-12-31', 1, isDebit: false);

        $report = $this->build()->buildPayables(CarbonImmutable::parse('2026-07-15'));

        $this->assertSame($report['control_balance'], $report['grand_total']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertSame(15000 + 3000 + 7000, $report['grand_total']);

        $vendorRow = collect($report['contacts'])->firstWhere('contact_id', $vendor->id);
        $this->assertNotNull($vendorRow);
        $this->assertSame(10000, $vendorRow['total'], 'both opening bills for the same vendor must be summed, neither dropped');
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Partial payment ages only the outstanding remainder.
    // ---------------------------------------------------------------

    public function test_partially_paid_invoice_ages_only_outstanding_remaining_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $invoice = $this->invoice($contact, 10000, '2026-06-01', '2026-06-10');
        $this->postInvoiceEntry($ar, $income, $invoice, 10000, '2026-06-01');
        $this->payInvoice($invoice, $ar, $bankLedger, $bankRow, 6000, '2026-06-15');

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertSame(4000, $row['total']);
        $this->assertSame(4000, $report['grand_total']);
    }

    public function test_partially_paid_bill_ages_only_outstanding_remaining_balance(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 10000, '2026-06-01', '2026-06-10');
        $this->postBillEntry($ap, $expense, $bill, 10000, '2026-06-01');
        $this->payBill($bill, $ap, $bankLedger, $bankRow, 4000, '2026-06-15');

        $report = $this->build()->buildPayables(CarbonImmutable::parse('2026-07-01'));

        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);
        $this->assertSame(6000, $row['total']);
        $this->assertSame(6000, $report['grand_total']);
    }

    // ---------------------------------------------------------------
    // Bucket boundaries (§12.9): exact edges.
    // ---------------------------------------------------------------

    public function test_bucket_boundaries_around_current_and_each_aging_band(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        $asOf = CarbonImmutable::parse('2026-07-31');

        // [amount, due_date, expected bucket key]
        $cases = [
            [100, '2026-07-31', 'current'],     // due exactly today -> Current
            [200, '2026-08-01', 'current'],     // not yet due
            [300, '2026-07-30', 'days_1_30'],   // 1 day past
            [400, '2026-07-01', 'days_1_30'],   // 30 days past
            [500, '2026-06-30', 'days_31_60'],  // 31 days past
            [600, '2026-05-31', 'days_61_90'],  // 61 days past
            [700, '2026-05-02', 'days_61_90'],  // 90 days past
            [800, '2026-05-01', 'days_90_plus'], // 91 days past
        ];

        foreach ($cases as $i => [$amount, $dueDate, $expectedBucket]) {
            $invoice = $this->invoice($contact, $amount, '2026-01-01', $dueDate);
            $this->postInvoiceEntry($ar, $income, $invoice, $amount, '2026-01-01');
        }

        $report = $this->build()->buildReceivables($asOf);
        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);

        foreach ($cases as [$amount, $dueDate, $expectedBucket]) {
            $this->assertGreaterThanOrEqual($amount, $row['buckets'][$expectedBucket], "amount {$amount} (due {$dueDate}) expected in bucket {$expectedBucket}");
        }

        // Precise per-bucket sums, since amounts are all distinct powers of the case list.
        $this->assertSame(100 + 200, $row['buckets']['current']);
        $this->assertSame(300 + 400, $row['buckets']['days_1_30']);
        $this->assertSame(500, $row['buckets']['days_31_60']);
        $this->assertSame(600 + 700, $row['buckets']['days_61_90']);
        $this->assertSame(800, $row['buckets']['days_90_plus']);
    }

    // ---------------------------------------------------------------
    // Null due_date fallback.
    // ---------------------------------------------------------------

    public function test_null_due_date_falls_back_to_current_bucket(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        // Issued long ago, so if the fallback used issue_date it would land
        // in 90+ instead — proves the fallback is genuinely "Current", not
        // an issue_date substitution.
        $invoice = $this->invoice($contact, 9000, '2026-01-01', null);
        $this->postInvoiceEntry($ar, $income, $invoice, 9000, '2026-01-01');

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-31'));
        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);

        $this->assertSame(9000, $row['buckets']['current']);
        $this->assertSame(0, $row['buckets']['days_90_plus']);
    }

    public function test_bill_null_due_date_also_falls_back_to_current_bucket(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 4000, '2026-01-01', null);
        $this->postBillEntry($ap, $expense, $bill, 4000, '2026-01-01');

        $report = $this->build()->buildPayables(CarbonImmutable::parse('2026-07-31'));
        $row = collect($report['contacts'])->firstWhere('contact_id', $contact->id);

        $this->assertSame(4000, $row['buckets']['current']);
    }

    // ---------------------------------------------------------------
    // Fully-paid / voided documents drop off entirely.
    // ---------------------------------------------------------------

    public function test_fully_paid_invoice_drops_off(): void
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

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
    }

    public function test_fully_paid_bill_drops_off(): void
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

        $report = $this->build()->buildPayables(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
    }

    public function test_voided_invoice_drops_off(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $contact = $this->contact($company);

        $invoice = $this->invoice($contact, 7000, '2026-06-01', '2026-06-10');
        $entry = $this->postInvoiceEntry($ar, $income, $invoice, 7000, '2026-06-01');
        $this->voidInvoice($invoice, $entry, '2026-06-05');

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
        $this->assertLedgerBalances();
    }

    public function test_voided_bill_drops_off(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ap = $this->apAccount($company);
        $expense = $this->expenseAccount($company);
        $contact = $this->contact($company);

        $bill = $this->bill($contact, 8000, '2026-06-01', '2026-06-10');
        $entry = $this->postBillEntry($ap, $expense, $bill, 8000, '2026-06-01');
        $this->voidBill($bill, $entry, '2026-06-05');

        $report = $this->build()->buildPayables(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertLedgerBalances();
    }

    public function test_voided_opening_run_drops_its_documents_off(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $obe = $this->obeAccount($company);
        $contact = $this->contact($company);

        $openingInvoice = $this->openingInvoice($contact, 6000, '2026-01-01', '2026-01-31');
        $this->postOpeningRun($ar, $obe, [['doc' => $openingInvoice, 'amount' => 6000]], '2025-12-31', 1, isDebit: true);

        // Simulate a re-run voiding this one, dated the SAME day as the
        // original (OpeningBalanceService::voidPriorRun's actual behavior).
        $entry = JournalEntry::query()
            ->where('source_type', 'opening_balance_run')
            ->where('source_id', 1)
            ->firstOrFail();
        DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse('2025-12-31'), 'Re-run voiding prior setup'));
        $openingInvoice->forceFill(['status' => InvoiceStatus::Void->value, 'voided_at' => now()])->save();

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $this->assertNull(collect($report['contacts'])->firstWhere('contact_id', $contact->id));
        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Per-contact subtotal == Σ its buckets; grand total == Σ contacts ==
    // control balance, across a mixed multi-contact scenario.
    // ---------------------------------------------------------------

    public function test_per_contact_subtotal_and_grand_total_invariants_hold(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $bankLedger = $this->bankLedgerAccount($company);
        $bankRow = $this->bankAccountRow($bankLedger);

        $alice = $this->contact($company);
        $bob = $this->contact($company);
        $carol = $this->contact($company);

        $inv1 = $this->invoice($alice, 10000, '2026-01-01', '2026-07-01'); // current
        $this->postInvoiceEntry($ar, $income, $inv1, 10000, '2026-01-01');

        $inv2 = $this->invoice($alice, 5000, '2026-01-01', '2026-06-01'); // overdue
        $this->postInvoiceEntry($ar, $income, $inv2, 5000, '2026-01-01');

        $inv3 = $this->invoice($bob, 20000, '2026-01-01', '2026-05-01');
        $this->postInvoiceEntry($ar, $income, $inv3, 20000, '2026-01-01');
        $this->payInvoice($inv3, $ar, $bankLedger, $bankRow, 12000, '2026-06-01');

        $inv4 = $this->invoice($carol, 3000, '2026-01-01', '2026-08-01');
        $this->postInvoiceEntry($ar, $income, $inv4, 3000, '2026-01-01');

        $asOf = CarbonImmutable::parse('2026-07-15');
        $report = $this->build()->buildReceivables($asOf);

        foreach ($report['contacts'] as $row) {
            $sumOfBuckets = array_sum($row['buckets']);
            $this->assertSame($sumOfBuckets, $row['total'], "contact {$row['contact_id']}'s subtotal must equal the sum of its own buckets");
        }

        $sumOfContacts = array_sum(array_column($report['contacts'], 'total'));
        $this->assertSame($sumOfContacts, $report['grand_total']);
        $this->assertSame($report['control_balance'], $report['grand_total']);
        $this->assertTrue($report['ties_to_control']);

        $sumOfTotalsRow = array_sum($report['totals']);
        $this->assertSame($sumOfTotalsRow, $report['grand_total']);
    }

    // ---------------------------------------------------------------
    // Cross-company isolation.
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_documents(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $contactA = $this->contact($companyA);
        $invoiceA = $this->invoice($contactA, 10000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arA, $incomeA, $invoiceA, 10000, '2026-01-01');

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $contactB = $this->contact($companyB);
        $invoiceB = $this->invoice($contactB, 4000, '2026-01-01', '2026-06-01');
        $this->postInvoiceEntry($arB, $incomeB, $invoiceB, 4000, '2026-01-01');

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(4000, $report['grand_total']);
        $ids = collect($report['contacts'])->pluck('contact_id')->all();
        $this->assertContains($contactB->id, $ids);
        $this->assertNotContains($contactA->id, $ids);
    }

    // ---------------------------------------------------------------
    // Empty company.
    // ---------------------------------------------------------------

    public function test_empty_company_returns_zero_and_ties_out(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $report = $this->build()->buildReceivables(CarbonImmutable::parse('2026-07-01'));

        $this->assertSame(0, $report['grand_total']);
        $this->assertSame(0, $report['control_balance']);
        $this->assertTrue($report['ties_to_control']);
        $this->assertSame([], $report['contacts']);
    }
}
