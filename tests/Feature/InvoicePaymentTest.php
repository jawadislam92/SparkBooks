<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class InvoicePaymentTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function customer(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ], $overrides));
    }

    private function arAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
            'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Builds an already-SENT invoice directly (bypassing InvoiceService::send
     * — that flow is InvoiceSendTest's concern), with amount_paid/balance
     * consistent with a fresh send: balance === total, amount_paid === 0.
     * This is a domestic-currency invoice throughout this test file
     * (fx_rate stays 1, base_* mirrors the document totals) — FX payments
     * are Task 8.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sentInvoice(Company $company, Contact $contact, int $total, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'issue_date' => '2026-07-01',
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
            'base_subtotal' => $total,
            'base_tax_total' => 0,
            'base_total' => $total,
        ], $overrides));
    }

    /** @return array{ar: Account} Standard chart this feature depends on (1100). */
    private function chart(Company $company): array
    {
        return ['ar' => $this->arAccount($company)];
    }

    /** @param array<string, mixed> $data */
    private function pay(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // -- helpers only needed by the "clears AR" test, which drives a real
    // draft -> send -> pay flow (via InvoiceService::send, already covered by
    // InvoiceSendTest) instead of hand-constructing a Sent row, so there's a
    // genuine P1 AR debit on the books for the payment to actually clear.

    /** @param array<string, mixed> $overrides */
    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function draftInvoice(Company $company, Contact $contact, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor: InvoiceLine::booted()'s freeze guard
     * re-queries Invoice with BelongsToCompany's global scope, which needs a
     * live CompanyContext — mirrors InvoiceSendTest::invoiceLine().
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoiceLine(Invoice $invoice, Account $incomeAccount, array $overrides = []): InvoiceLine
    {
        $company = Company::query()->findOrFail($invoice->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => InvoiceLine::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'income_account_id' => $incomeAccount->id,
        ], $overrides)));
    }

    private function sendInvoiceHttp(Invoice $invoice): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/send", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // P2: full payment
    // ---------------------------------------------------------------

    public function test_full_payment_posts_p2_exactly_and_marks_the_invoice_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 118000);

        $response = $this->pay($invoice, [
            'amount' => 118000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertRedirect(route('invoices.edit', $invoice->id));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(118000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->balance);

        $payment = InvoicePayment::query()->sole();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame(118000, $payment->amount);
        $this->assertSame(118000, $payment->base_amount);
        $this->assertSame($bank->id, $payment->bank_account_id);
        $this->assertNull($payment->fx_rate);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('2026-07-15', $payment->payment_date->toDateString());

        $entry = JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->sole();
        $this->assertCount(2, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(118000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);
        $this->assertSame(0, $arLine->debit);
        $this->assertSame(118000, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'invoice.payment_recorded')->sole();
        $this->assertSame($payment->id, $audit->subject_id);
        $this->assertSame($invoice->id, $audit->after['invoice_id']);
        $this->assertSame(118000, $audit->after['amount']);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    // ---------------------------------------------------------------
    // P3: partial payment
    // ---------------------------------------------------------------

    public function test_partial_payment_posts_p3_and_marks_the_invoice_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);

        $response = $this->pay($invoice, [
            'amount' => 50000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(50000, $invoice->amount_paid);
        $this->assertSame(50000, $invoice->balance);

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    public function test_multiple_partials_summing_to_the_full_balance_end_in_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);

        $this->pay($invoice, ['amount' => 30000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(30000, $invoice->amount_paid);

        $this->pay($invoice, ['amount' => 30000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12'])->assertRedirect();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(60000, $invoice->amount_paid);

        $this->pay($invoice, ['amount' => 40000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-14'])->assertRedirect();
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->balance);

        $this->assertSame(3, InvoicePayment::query()->count());
        $this->assertSame(3, JournalEntry::query()->where('source_type', 'invoice_payment')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Overpayment rejected
    // ---------------------------------------------------------------

    public function test_overpayment_beyond_the_full_balance_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);

        $response = $this->pay($invoice, [
            'amount' => 100001,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice_payment')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(100000, $invoice->balance);
        $this->assertLedgerBalances();
    }

    public function test_a_payment_exceeding_the_remaining_balance_after_a_partial_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);
        $this->pay($invoice, ['amount' => 60000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        // Remaining balance is 40000 — 40001 must be rejected.
        $response = $this->pay($invoice, ['amount' => 40001, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(1, InvoicePayment::query()->count(), 'Only the first (accepted) payment should exist.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(60000, $invoice->amount_paid);
        $this->assertSame(40000, $invoice->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Payment clears AR
    // ---------------------------------------------------------------

    public function test_full_payment_clears_the_contacts_ar_balance_back_to_zero(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        // Real draft -> send flow (InvoiceService::send, already covered by
        // InvoiceSendTest) so there is a genuine P1 AR debit on the books —
        // not a hand-rolled ledger row — for the payment to actually clear.
        $draft = $this->draftInvoice($company, $contact);
        $this->invoiceLine($draft, $income, ['quantity' => '1', 'unit_price' => 75000, 'tax_rate_id' => null]);
        $this->sendInvoiceHttp($draft)->assertRedirect();
        $invoice = $draft->fresh();

        $this->pay($invoice, ['amount' => 75000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        $arBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ar']->id)
            ->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        $this->assertSame(0, (int) $arBalance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Concurrency (spec §9): overpayment guard on a lockForUpdate() read.
    //
    // A genuine two-connection race is deliberately NOT simulated here — same
    // RefreshDatabase deadlock/flakiness concern documented in
    // IdempotencyTest/OpeningBalancesTest/BankAccountsTest (fixture rows live
    // inside RefreshDatabase's still-open, uncommitted outer transaction on
    // the default connection, so they're invisible to a genuinely separate
    // session — a second connection's lockForUpdate() on the same row would
    // just see "no such row" rather than actually blocking on the lock).
    // Instead: (1) assert the SELECT that recordPayment issues on the
    // invoice is actually a FOR UPDATE, which is what makes the lock hold,
    // and (2) prove the guard reads the LOCKED row's fresh state by showing
    // a second call, once the first has committed, correctly sees the
    // reduced balance and is rejected — exactly what would happen to the
    // loser of a real race once the winner's transaction commits and
    // releases the lock.
    // ---------------------------------------------------------------

    public function test_record_payment_locks_the_invoice_row_with_a_locking_select(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);

        DB::enableQueryLog();
        $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains(strtolower($q), 'from `invoices`')
                && str_contains(strtolower($q), 'for update')),
            'recordPayment must lock the invoice row (lockForUpdate) before checking the outstanding balance.'
        );
    }

    public function test_second_concurrent_style_payment_after_the_first_commits_cannot_overpay(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 100000);

        // "Winner": a full payment for the whole balance.
        $this->pay($invoice, ['amount' => 100000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        // "Loser": had it raced against a stale (pre-payment) balance read it
        // might have believed the invoice still owed 100000 — but the locked
        // re-read inside the transaction sees the now-Paid, 0-balance row and
        // rejects it, exactly as the loser of a genuine race would once it
        // acquires the lock after the winner released it.
        $second = $this->pay($invoice, ['amount' => 100000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-16']);

        $second->assertSessionHasErrors('status');
        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice_payment')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Guards: not-payable statuses
    // ---------------------------------------------------------------

    public function test_cannot_pay_a_draft_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'total' => 50000,
            'balance' => 50000,
        ]);

        $response = $this->pay($invoice, ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_cannot_pay_a_void_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 50000, [
            'status' => InvoiceStatus::Void->value,
            'voided_at' => now(),
        ]);

        $response = $this->pay($invoice, ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_posts_exactly_one_payment_and_one_entry(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $data = ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'];
        $first = $this->pay($invoice, $data, $key);
        $second = $this->pay($invoice, $data, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice_payment')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.payment_recorded')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(50000, $invoice->amount_paid);
        $this->assertLedgerBalances();
    }

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $response = $this->postJson("/invoices/{$invoice->id}/payments", [
            'amount' => 50000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Bank account: tenant + non-archived
    // ---------------------------------------------------------------

    public function test_a_foreign_bank_account_returns_422(): void
    {
        $companyA = Company::factory()->create();
        $foreignBank = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $contact = $this->customer($companyB);
        $this->chart($companyB);

        $invoice = $this->sentInvoice($companyB, $contact, 50000);

        $response = $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $foreignBank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_an_archived_bank_account_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company, ['is_archived' => true]);

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $response = $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Amount guard
    // ---------------------------------------------------------------

    public function test_a_zero_amount_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $response = $this->pay($invoice, ['amount' => 0, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_paying_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $response = $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-06-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice_payment')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: payments.record — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_record_a_payment(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->customer($company);
            $this->chart($company);
            $bank = $this->bankAccount($company);

            $invoice = $this->sentInvoice($company, $contact, 50000);

            $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_recording_a_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentInvoice($company, $contact, 50000);

        $response = $this->pay($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertForbidden();
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_invoice_404s_on_payment(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $foreignInvoice = $this->sentInvoice($companyA, $contactA, 50000);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bank = $this->bankAccount($companyB);

        $this->pay($foreignInvoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])
            ->assertNotFound();
    }
}
