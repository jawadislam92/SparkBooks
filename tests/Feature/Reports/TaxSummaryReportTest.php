<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ManualJournal;
use App\Services\LedgerService;
use App\Services\Reports\LedgerBalances;
use App\Services\Reports\TaxSummaryReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * §7.11.6's classification trap, under test throughout: collected vs. input
 * is classified by the ENTRY's source_type, never by which column
 * (debit/credit) an amount sits in — a reversal DEBITS 2100 for an invoice
 * void (which must decrease collected) exactly like a normal expense/bill
 * does (which increases input), so a column-based rule would misfile the
 * void into input. See test_voiding_an_invoice_decreases_collected_leaves_
 * input_unchanged and its bill-side mirror below.
 *
 * Builds documents + matching ledger entries directly (the "lighter style"
 * AgingReportTest/BalanceSheetReportTest use for report-service-level
 * tests) rather than driving the full HTTP record/send flows, since the
 * report needs real Expense/Bill/ExpenseLine/BillLine rows to classify
 * against (see TaxSummaryReport's own class docblock for why).
 */
class TaxSummaryReportTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    /** @return array{ar: Account, ap: Account, income: Account, category: Account, bank: Account, taxPayable: Account} */
    private function chart(Company $company): array
    {
        return [
            'ar' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '1100', 'name' => 'Accounts Receivable',
                'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Receivable->value,
            ]),
            'ap' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '2000', 'name' => 'Accounts Payable',
                'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Payable->value,
            ]),
            'income' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '4000', 'name' => 'Sales',
                'type' => AccountType::Income->value, 'subtype' => AccountSubtype::OperatingIncome->value,
            ]),
            'category' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '5000', 'name' => 'Office expenses',
                'type' => AccountType::Expense->value, 'subtype' => AccountSubtype::OperatingExpense->value,
            ]),
            'bank' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '1010', 'name' => 'Bank',
                'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Bank->value,
            ]),
            'taxPayable' => Account::factory()->create([
                'company_id' => $company->id, 'code' => '2100', 'name' => 'Tax Payable',
                'type' => AccountType::Liability->value, 'subtype' => AccountSubtype::Tax->value, 'is_system' => true,
            ]),
        ];
    }

    private function customer(Company $company): Contact
    {
        return Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value]);
    }

    private function vendor(Company $company): Contact
    {
        return Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Vendor->value]);
    }

    // ---------------------------------------------------------------
    // Document + ledger builders -- mirror the real posting shapes
    // (InvoiceService::p1Lines / ExpenseService::p4Lines / BillService::
    // p6Lines) directly via LedgerService, the same lighter style
    // AgingReportTest/BalanceSheetReportTest use for report-service-level
    // tests.
    // ---------------------------------------------------------------

    private function invoice(Contact $contact, int $net, int $tax): Invoice
    {
        return Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'subtotal' => $net, 'tax_total' => $tax, 'total' => $net + $tax,
            'balance' => $net + $tax,
            'base_subtotal' => $net, 'base_tax_total' => $tax, 'base_total' => $net + $tax,
            'sent_at' => now(),
        ]);
    }

    /** Dr AR (net+tax) / Cr income (net) / Cr 2100 (tax) -- mirrors InvoiceService::p1Lines. */
    private function postInvoiceEntry(Invoice $invoice, int $net, int $tax, string $date, array $accounts): JournalEntry
    {
        $lines = [
            new LineDraft(accountId: $accounts['ar']->id, debit: $net + $tax, credit: 0, contactId: $invoice->contact_id),
            new LineDraft(accountId: $accounts['income']->id, debit: 0, credit: $net),
        ];
        if ($tax > 0) {
            $lines[] = new LineDraft(accountId: $accounts['taxPayable']->id, debit: 0, credit: $tax);
        }

        return $this->postEntry('invoice', $invoice->id, $lines, $date);
    }

    /**
     * Created as Draft, NOT Recorded — ExpenseLine::booted()'s freeze guard
     * rejects a line write once the parent expense has left draft, so any
     * caller adding expenseLineRow()s must do so BEFORE calling
     * recordExpense() below.
     */
    private function expense(int $net, int $tax): Expense
    {
        return Expense::factory()->create([
            'status' => ExpenseStatus::Draft->value,
            'subtotal' => $net, 'tax_total' => $tax, 'total' => $net + $tax,
        ]);
    }

    private function recordExpense(Expense $expense): void
    {
        $expense->forceFill(['status' => ExpenseStatus::Recorded->value, 'recorded_at' => now()])->save();
    }

    private function expenseLineRow(Expense $expense, Account $category, int $amount): ExpenseLine
    {
        return ExpenseLine::factory()->create([
            'expense_id' => $expense->id,
            'category_account_id' => $category->id,
            'amount' => $amount,
            'tax_rate_id' => null,
            'line_tax' => 0,
        ]);
    }

    /**
     * Posts an expense's P4/P5 entry from an explicit list of [account,
     * amount] category lines (one per expense_lines row the caller already
     * created) plus an optional combined 2100 tax line -- mirrors
     * ExpenseService::p4Lines exactly, including its P12 case: a category
     * line that happens to BE 2100 (remittance) is just an ordinary entry
     * in $categoryLines, entirely independent of $taxTotal.
     *
     * @param  list<array{0: Account, 1: int}>  $categoryLines
     */
    private function postExpenseEntry(Expense $expense, array $categoryLines, int $taxTotal, string $date, array $accounts): JournalEntry
    {
        $lines = [];
        $total = 0;
        foreach ($categoryLines as [$account, $amount]) {
            $total += $amount;
            if ($amount > 0) {
                $lines[] = new LineDraft(accountId: $account->id, debit: $amount, credit: 0);
            }
        }
        if ($taxTotal > 0) {
            $lines[] = new LineDraft(accountId: $accounts['taxPayable']->id, debit: $taxTotal, credit: 0);
        }
        $lines[] = new LineDraft(accountId: $accounts['bank']->id, debit: 0, credit: $total + $taxTotal);

        return $this->postEntry('expense', $expense->id, $lines, $date);
    }

    /**
     * Created as Draft, NOT Received -- BillLine::booted()'s freeze guard
     * rejects a line write once the parent bill has left draft, so any
     * caller adding billLineRow()s must do so BEFORE calling receiveBill()
     * below (mirrors expense()/recordExpense() above).
     */
    private function bill(Contact $contact, int $net, int $tax): Bill
    {
        return Bill::factory()->create([
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
            'subtotal' => $net, 'tax_total' => $tax, 'total' => $net + $tax,
            'balance' => $net + $tax,
        ]);
    }

    private function receiveBill(Bill $bill): void
    {
        $bill->forceFill(['status' => BillStatus::Received->value, 'received_at' => now()])->save();
    }

    private function billLineRow(Bill $bill, Account $category, int $amount): BillLine
    {
        return BillLine::factory()->create([
            'bill_id' => $bill->id,
            'category_account_id' => $category->id,
            'amount' => $amount,
            'tax_rate_id' => null,
            'line_tax' => 0,
        ]);
    }

    /**
     * @param  list<array{0: Account, 1: int}>  $categoryLines
     */
    private function postBillEntry(Bill $bill, array $categoryLines, int $taxTotal, string $date, array $accounts): JournalEntry
    {
        $lines = [];
        $total = 0;
        foreach ($categoryLines as [$account, $amount]) {
            $total += $amount;
            if ($amount > 0) {
                $lines[] = new LineDraft(accountId: $account->id, debit: $amount, credit: 0);
            }
        }
        if ($taxTotal > 0) {
            $lines[] = new LineDraft(accountId: $accounts['taxPayable']->id, debit: $taxTotal, credit: 0);
        }
        $lines[] = new LineDraft(accountId: $accounts['ap']->id, debit: 0, credit: $total + $taxTotal, contactId: $bill->contact_id);

        return $this->postEntry('bill', $bill->id, $lines, $date);
    }

    private function manualAdjustment(Account $other, Account $taxPayable, int $amount, bool $creditsTax, string $date): JournalEntry
    {
        $journal = ManualJournal::factory()->create(['entry_date' => $date, 'status' => 'posted']);

        $lines = $creditsTax
            ? [new LineDraft(accountId: $other->id, debit: $amount, credit: 0), new LineDraft(accountId: $taxPayable->id, debit: 0, credit: $amount)]
            : [new LineDraft(accountId: $taxPayable->id, debit: $amount, credit: 0), new LineDraft(accountId: $other->id, debit: 0, credit: $amount)];

        return $this->postEntry('manual_journal', $journal->id, $lines, $date);
    }

    /** @param  list<LineDraft>  $lines */
    private function postEntry(string $sourceType, int $sourceId, array $lines, string $date): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Test entry',
            sourceType: $sourceType,
            sourceId: $sourceId,
            lines: $lines,
        )));
    }

    private function voidEntry(JournalEntry $entry, string $date): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->reverse($entry, CarbonImmutable::parse($date), 'Void'));
    }

    private function build(string $from, string $to): array
    {
        return app(TaxSummaryReport::class)->build(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    // ---------------------------------------------------------------
    // Basic classification: invoice -> collected; expense/bill -> input
    // ---------------------------------------------------------------

    public function test_invoice_with_tax_increases_collected_input_unchanged(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $customer = $this->customer($company);

        $invoice = $this->invoice($customer, 10000, 1800);
        $this->postInvoiceEntry($invoice, 10000, 1800, '2026-07-05', $accounts);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(1800, $report['collected']);
        $this->assertSame(0, $report['input']);
        $this->assertSame(0, $report['remittances']);
        $this->assertSame(0, $report['adjustments']);
        $this->assertSame(1800, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    public function test_expense_and_bill_with_input_tax_increase_input_collected_unchanged(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $vendor = $this->vendor($company);

        $expense = $this->expense(10000, 1800);
        $this->recordExpense($expense);
        $this->postExpenseEntry($expense, [[$accounts['category'], 10000]], 1800, '2026-07-05', $accounts);

        $bill = $this->bill($vendor, 5000, 900);
        $this->receiveBill($bill);
        $this->postBillEntry($bill, [[$accounts['category'], 5000]], 900, '2026-07-06', $accounts);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(0, $report['collected']);
        $this->assertSame(2700, $report['input']);
        $this->assertSame(0, $report['remittances']);
        $this->assertSame(0, $report['adjustments']);
        $this->assertSame(-2700, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // THE TRAP: voiding an invoice must decrease collected only -- the
    // reversal DEBITS 2100 (indistinguishable, by column alone, from an
    // expense's own debit), yet it must never land in input.
    // ---------------------------------------------------------------

    public function test_voiding_an_invoice_decreases_collected_leaves_input_unchanged(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $customer = $this->customer($company);

        $invoice = $this->invoice($customer, 10000, 1800);
        $invoiceEntry = $this->postInvoiceEntry($invoice, 10000, 1800, '2026-07-05', $accounts);

        // Unrelated input-tax activity in the same period -- proves voiding
        // the invoice never touches input.
        $expense = $this->expense(5000, 900);
        $this->recordExpense($expense);
        $this->postExpenseEntry($expense, [[$accounts['category'], 5000]], 900, '2026-07-06', $accounts);

        $before = $this->build('2026-07-01', '2026-07-09');
        $this->assertSame(1800, $before['collected']);
        $this->assertSame(900, $before['input']);

        $this->voidEntry($invoiceEntry, '2026-07-10');

        $after = $this->build('2026-07-01', '2026-07-31');
        // Collected decreased by exactly the voided tax -- net to zero here
        // since both the send and the void fall inside this window.
        $this->assertSame($before['collected'] - 1800, $after['collected']);
        $this->assertSame(0, $after['collected']);
        // Input is completely unaffected by the invoice void.
        $this->assertSame($before['input'], $after['input']);
        $this->assertLedgerBalances();
    }

    public function test_voiding_an_expense_decreases_input_leaves_collected_unchanged(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $customer = $this->customer($company);

        $expense = $this->expense(10000, 1800);
        $this->recordExpense($expense);
        $expenseEntry = $this->postExpenseEntry($expense, [[$accounts['category'], 10000]], 1800, '2026-07-05', $accounts);

        // Unrelated collected-tax activity -- proves voiding the expense
        // never touches collected.
        $invoice = $this->invoice($customer, 5000, 900);
        $this->postInvoiceEntry($invoice, 5000, 900, '2026-07-06', $accounts);

        $before = $this->build('2026-07-01', '2026-07-09');
        $this->assertSame(1800, $before['input']);
        $this->assertSame(900, $before['collected']);

        $this->voidEntry($expenseEntry, '2026-07-10');

        $after = $this->build('2026-07-01', '2026-07-31');
        $this->assertSame($before['input'] - 1800, $after['input']);
        $this->assertSame(0, $after['input']);
        $this->assertSame($before['collected'], $after['collected']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // P12 tax remittance: its own line, never negative input, never
    // collected -- the second-level discriminator (source document's
    // category_account_id vs. tax_total).
    // ---------------------------------------------------------------

    public function test_p12_tax_remittance_shows_as_its_own_line_not_input_not_collected(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);

        $remittance = $this->expense(5000, 0);
        $this->expenseLineRow($remittance, $accounts['taxPayable'], 5000);
        $this->recordExpense($remittance);
        $this->postExpenseEntry($remittance, [[$accounts['taxPayable'], 5000]], 0, '2026-07-05', $accounts);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(5000, $report['remittances']);
        $this->assertSame(0, $report['input']);
        $this->assertSame(0, $report['collected']);
        $this->assertSame(-5000, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    /** Defensive coverage: the same category-is-2100 remittance detection applied on the bill side. */
    public function test_bill_remittance_shows_as_its_own_line_not_input_not_collected(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $vendor = $this->vendor($company);

        $remittance = $this->bill($vendor, 3000, 0);
        $this->billLineRow($remittance, $accounts['taxPayable'], 3000);
        $this->receiveBill($remittance);
        $this->postBillEntry($remittance, [[$accounts['taxPayable'], 3000]], 0, '2026-07-05', $accounts);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(3000, $report['remittances']);
        $this->assertSame(0, $report['input']);
        $this->assertSame(0, $report['collected']);
        $this->assertSame(-3000, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    /**
     * The hardest case: ONE expense with BOTH a remittance component
     * (category = 2100) and an ordinary input-tax component (tax_total,
     * from an unrelated taxable line) in the SAME journal entry, on the
     * SAME account -- journal_lines alone can't tell these two Dr 2100
     * lines apart (see TaxSummaryReport's class docblock); only the source
     * document's own frozen fields can.
     */
    public function test_expense_with_both_remittance_and_input_tax_splits_correctly(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);

        $expense = $this->expense(10000, 1800);
        $this->expenseLineRow($expense, $accounts['category'], 10000);
        $this->expenseLineRow($expense, $accounts['taxPayable'], 5000);
        $this->recordExpense($expense);
        $this->postExpenseEntry(
            $expense,
            [[$accounts['category'], 10000], [$accounts['taxPayable'], 5000]],
            1800,
            '2026-07-05',
            $accounts,
        );

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(1800, $report['input']);
        $this->assertSame(5000, $report['remittances']);
        $this->assertSame(0, $report['collected']);
        $this->assertSame(-6800, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Manual adjustments
    // ---------------------------------------------------------------

    public function test_manual_journal_touching_2100_shows_as_adjustment(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);

        // Credits 2100 (increases the liability) -- e.g. a correcting entry.
        $this->manualAdjustment($accounts['category'], $accounts['taxPayable'], 1200, true, '2026-07-05');

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(1200, $report['adjustments']);
        $this->assertSame(0, $report['collected']);
        $this->assertSame(0, $report['input']);
        $this->assertSame(0, $report['remittances']);
        $this->assertSame(1200, $report['net_tax_payable']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Tie-out (§12.9-style gate): net == the whole 2100 account's period
    // movement, regardless of how many source types touched it.
    // ---------------------------------------------------------------

    public function test_net_tax_payable_ties_to_2100_period_movement(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $customer = $this->customer($company);
        $vendor = $this->vendor($company);

        $invoice = $this->invoice($customer, 10000, 1800);
        $this->postInvoiceEntry($invoice, 10000, 1800, '2026-07-02', $accounts);

        $expense = $this->expense(4000, 720);
        $this->recordExpense($expense);
        $expenseEntry = $this->postExpenseEntry($expense, [[$accounts['category'], 4000]], 720, '2026-07-03', $accounts);

        $bill = $this->bill($vendor, 3000, 540);
        $this->receiveBill($bill);
        $this->postBillEntry($bill, [[$accounts['category'], 3000]], 540, '2026-07-04', $accounts);

        $remittance = $this->expense(2000, 0);
        $this->expenseLineRow($remittance, $accounts['taxPayable'], 2000);
        $this->recordExpense($remittance);
        $this->postExpenseEntry($remittance, [[$accounts['taxPayable'], 2000]], 0, '2026-07-05', $accounts);

        $this->manualAdjustment($accounts['category'], $accounts['taxPayable'], 300, true, '2026-07-06');

        // Void the expense mid-period too, so the tie-out is proven across a
        // mix of primary AND reversal entries, not just primaries.
        $this->voidEntry($expenseEntry, '2026-07-08');

        $report = $this->build('2026-07-01', '2026-07-31');

        $expectedNet = $report['collected'] - $report['input'] - $report['remittances'] + $report['adjustments'];
        $this->assertSame($expectedNet, $report['net_tax_payable']);

        $movement = app(LedgerBalances::class)->movement(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
            [$accounts['taxPayable']->id],
        );
        $movementNet = $movement[$accounts['taxPayable']->id]['net'] ?? 0; // debit - credit
        $this->assertSame($report['net_tax_payable'], -$movementNet);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Period filtering + empty period
    // ---------------------------------------------------------------

    public function test_period_filtering_only_includes_in_range_entries(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $accounts = $this->chart($company);
        $customer = $this->customer($company);

        $before = $this->invoice($customer, 1000, 180);
        $this->postInvoiceEntry($before, 1000, 180, '2026-06-30', $accounts);

        $inRange = $this->invoice($customer, 10000, 1800);
        $this->postInvoiceEntry($inRange, 10000, 1800, '2026-07-05', $accounts);

        $after = $this->invoice($customer, 2000, 360);
        $this->postInvoiceEntry($after, 2000, 360, '2026-08-01', $accounts);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(1800, $report['collected']);
    }

    public function test_empty_period_returns_zeros(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $this->chart($company);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(0, $report['collected']);
        $this->assertSame(0, $report['input']);
        $this->assertSame(0, $report['remittances']);
        $this->assertSame(0, $report['adjustments']);
        $this->assertSame(0, $report['net_tax_payable']);
    }

    public function test_no_2100_account_at_all_returns_zeros_not_an_error(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        // No taxPayable account created at all for this company.
        Account::factory()->create([
            'company_id' => $company->id, 'code' => '1010',
            'type' => AccountType::Asset->value, 'subtype' => AccountSubtype::Bank->value,
        ]);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(0, $report['net_tax_payable']);
    }

    // ---------------------------------------------------------------
    // Cross-company isolation
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_activity(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $accountsA = $this->chart($companyA);
        $customerA = $this->customer($companyA);
        $invoiceA = $this->invoice($customerA, 10000, 1800);
        $this->postInvoiceEntry($invoiceA, 10000, 1800, '2026-07-05', $accountsA);

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->set($companyB);
        $accountsB = $this->chart($companyB);
        $customerB = $this->customer($companyB);
        $invoiceB = $this->invoice($customerB, 5000, 900);
        $this->postInvoiceEntry($invoiceB, 5000, 900, '2026-07-05', $accountsB);

        $report = $this->build('2026-07-01', '2026-07-31');

        $this->assertSame(900, $report['collected']);
    }
}
