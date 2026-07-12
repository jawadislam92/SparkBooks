<?php

namespace Tests\Support;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\OpeningDocument;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixture/posting-helper trait for Phase 7 Task 6's report suite
 * (Customer/Vendor Balance, Open Invoices, Unpaid Bills, Collections,
 * Customer/Vendor Statement) — extracted rather than duplicated across the
 * ~14 test files covering those reports,
 * mirroring AgingReportTest's own private builders almost verbatim (same
 * shapes, so the same "mirrors the real posting rules" confidence applies)
 * PLUS an FX invoice/payment pair (postFxInvoiceEntry/payFxInvoice) that
 * AgingReportTest doesn't need but this task's FX-correctness gate does: an
 * invoice whose document-currency total is wildly different from its
 * base-currency total, so a report that accidentally summed the wrong one
 * would be caught immediately (orders of magnitude off, not an off-by-one).
 */
trait ContactLedgerTestHelpers
{
    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

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

    /** @param  array<string, mixed>  $overrides */
    private function contact(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    private function invoice(Contact $contact, int $amount, string $issueDate, ?string $dueDate, string $status = 'sent'): Invoice
    {
        return Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
            'issue_date' => $issueDate,
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
            'is_opening' => false,
            'sent_at' => now(),
        ]);
    }

    /**
     * A foreign-currency invoice whose DOCUMENT total ($docAmount, in the
     * document currency's minor units) is deliberately nowhere near its BASE
     * total ($baseAmount, PKR minor units) — e.g. USD 1,000.00 (100000) vs
     * PKR 280,000.00 (28000000) at fx_rate 280. Any report that summed
     * `total`/`balance` instead of the base-currency ledger would be off by
     * orders of magnitude, not a rounding cent, making the bug impossible to
     * miss in a test assertion.
     */
    private function fxInvoice(Contact $contact, int $docAmount, int $baseAmount, string $fxRate, string $issueDate, ?string $dueDate, string $status = 'sent'): Invoice
    {
        return Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'currency_code' => 'USD',
            'fx_rate' => $fxRate,
            'subtotal' => $docAmount,
            'tax_total' => 0,
            'total' => $docAmount,
            'amount_paid' => 0,
            'balance' => $docAmount,
            'base_subtotal' => $baseAmount,
            'base_tax_total' => 0,
            'base_total' => $baseAmount,
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

    /** Dr AR, Cr income — mirrors InvoiceService::send()'s P1 (domestic: doc amount === base amount). */
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

    /** Dr AR (BASE), Cr income (BASE) — mirrors InvoiceService::send()'s P1fx: posts the BASE-currency amount, never the document-currency total. */
    private function postFxInvoiceEntry(Account $ar, Account $income, Invoice $invoice, int $baseAmount, string $date): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'FX invoice',
            sourceType: 'invoice',
            sourceId: $invoice->id,
            lines: [
                new LineDraft(accountId: $ar->id, debit: $baseAmount, credit: 0, contactId: $invoice->contact_id),
                new LineDraft(accountId: $income->id, debit: 0, credit: $baseAmount),
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

    /** Dr bank, Cr AR — mirrors InvoicePaymentService's P2 (domestic). */
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

    /**
     * A payment against an FX invoice — mirrors InvoicePaymentService's
     * P2fx: `amount` is the document-currency (USD) amount the payment
     * record shows, `baseArRelief` is the BASE (PKR) amount actually
     * relieved from the ledger's AR balance (per spec §17.1, computed by the
     * real service at the invoice's own frozen fx_rate, or — for the final
     * payment that zeroes a document out — at whatever base AR is still
     * actually outstanding). This helper posts a plain 2-line Dr bank(base)/
     * Cr AR(base) entry — no FX gain/loss line — since gain/loss lands on a
     * different account entirely and is irrelevant to this report's AR math.
     */
    private function payFxInvoice(Invoice $invoice, Account $ar, Account $bankLedger, BankAccount $bankRow, int $docAmount, int $baseArRelief, string $fxRate, string $date): InvoicePayment
    {
        $payment = InvoicePayment::factory()->create([
            'invoice_id' => $invoice->id,
            'payment_date' => $date,
            'amount' => $docAmount,
            'base_amount' => $baseArRelief,
            'base_ar_relief' => $baseArRelief,
            'bank_account_id' => $bankRow->id,
            'fx_rate' => $fxRate,
            'status' => 'posted',
        ]);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'FX invoice payment',
            sourceType: 'invoice_payment',
            sourceId: $payment->id,
            lines: [
                new LineDraft(accountId: $bankLedger->id, debit: $baseArRelief, credit: 0),
                new LineDraft(accountId: $ar->id, debit: 0, credit: $baseArRelief, contactId: $invoice->contact_id),
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

    /**
     * @param  list<array{doc: Invoice|Bill, amount:int}>  $docs
     */
    private function postOpeningRun(Account $controlAccount, Account $obe, array $docs, string $entryDate, bool $isDebit): OpeningBalanceRun
    {
        $run = OpeningBalanceRun::factory()->create([
            'books_start_date' => CarbonImmutable::parse($entryDate)->addDay()->toDateString(),
            'status' => 'active',
        ]);

        $lines = [];
        $total = 0;
        foreach ($docs as $entry) {
            $doc = $entry['doc'];
            $amount = $entry['amount'];
            $lines[] = $isDebit
                ? new LineDraft(accountId: $controlAccount->id, debit: $amount, credit: 0, contactId: $doc->contact_id)
                : new LineDraft(accountId: $controlAccount->id, debit: 0, credit: $amount, contactId: $doc->contact_id);
            $total += $amount;

            OpeningDocument::factory()->create([
                'opening_balance_run_id' => $run->id,
                'contact_id' => $doc->contact_id,
                'invoice_id' => $doc instanceof Invoice ? $doc->id : null,
                'bill_id' => $doc instanceof Bill ? $doc->id : null,
                'kind' => $isDebit ? 'receivable' : 'payable',
                'original_date' => $entryDate,
                'due_date' => $doc->due_date->toDateString(),
                'amount' => $amount,
                'status' => 'open',
            ]);
        }
        $lines[] = $isDebit
            ? new LineDraft(accountId: $obe->id, debit: 0, credit: $total)
            : new LineDraft(accountId: $obe->id, debit: $total, credit: 0);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($entryDate),
            memo: 'Opening balances',
            sourceType: 'opening_balance_run',
            sourceId: $run->id,
            lines: $lines,
        )));

        return $run;
    }
}
