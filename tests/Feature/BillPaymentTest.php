<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Posting rule P7 (spec §6): Dr 2000 Accounts Payable (amount, contactId =
 * bill.contact_id) / Cr the paying bank account's linked ledger account
 * (amount) — the AP-side mirror of InvoicePaymentTest (P3-T4). Bills are
 * base-currency-only in this phase (Phase 4 brief) — no P7fx counterpart, so
 * this file has no FX section unlike InvoicePaymentTest/InvoiceFxPaymentTest.
 */
class BillPaymentTest extends TestCase
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
    private function vendor(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ], $overrides));
    }

    private function apAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
            'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Builds an already-Received bill directly (bypassing BillService::receive
     * — that flow is BillReceiveTest's concern), with amount_paid/balance
     * consistent with a fresh receive: balance === total, amount_paid === 0.
     * Mirrors InvoicePaymentTest::sentInvoice.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function receivedBill(Company $company, Contact $contact, int $total, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Received->value,
            'due_date' => '2026-08-01',
            'bill_date' => '2026-07-01',
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
            'received_at' => now(),
        ], $overrides));
    }

    /** @return array{ap: Account} Standard chart this feature depends on (2000). */
    private function chart(Company $company): array
    {
        return ['ap' => $this->apAccount($company)];
    }

    /** @param array<string, mixed> $data */
    private function pay(Bill $bill, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // -- helpers only needed by the "clears AP" test, which drives a real
    // draft -> receive -> pay flow (via BillService::receive, already covered
    // by BillReceiveTest) instead of hand-constructing a Received row, so
    // there's a genuine P6 AP credit on the books for the payment to
    // actually clear.

    /** @param array<string, mixed> $overrides */
    private function draftBill(Company $company, Contact $contact, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
            'due_date' => '2026-08-01',
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor: BillLine::booted()'s freeze guard
     * re-queries Bill with BelongsToCompany's global scope, which needs a
     * live CompanyContext — mirrors BillReceiveTest::billLine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function billLine(Bill $bill, Account $categoryAccount, array $overrides = []): BillLine
    {
        $company = Company::query()->findOrFail($bill->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => BillLine::factory()->create(array_merge([
            'bill_id' => $bill->id,
            'company_id' => $bill->company_id,
            'category_account_id' => $categoryAccount->id,
        ], $overrides)));
    }

    private function receiveBillHttp(Bill $bill): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/receive", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // P7: full payment
    // ---------------------------------------------------------------

    public function test_full_payment_posts_p7_exactly_and_marks_the_bill_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 118000);

        $response = $this->pay($bill, [
            'amount' => 118000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertRedirect(route('bills.show', $bill->id));

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame(118000, $bill->amount_paid);
        $this->assertSame(0, $bill->balance);

        $payment = BillPayment::query()->sole();
        $this->assertSame($bill->id, $payment->bill_id);
        $this->assertSame(118000, $payment->amount);
        $this->assertSame($bank->id, $payment->bank_account_id);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('2026-07-15', $payment->payment_date->toDateString());

        $entry = JournalEntry::query()
            ->where('source_type', 'bill_payment')->where('source_id', $payment->id)
            ->sole();
        $this->assertCount(2, $entry->lines);

        $apLine = $entry->lines->firstWhere('account_id', $chart['ap']->id);
        $this->assertNotNull($apLine);
        $this->assertSame(118000, $apLine->debit);
        $this->assertSame(0, $apLine->credit);
        $this->assertSame($contact->id, $apLine->contact_id);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(118000, $bankLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'bill_payment.recorded')->sole();
        $this->assertSame($payment->id, $audit->subject_id);
        $this->assertSame($bill->id, $audit->after['bill_id']);
        $this->assertSame(118000, $audit->after['amount']);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    // ---------------------------------------------------------------
    // P7: partial payment
    // ---------------------------------------------------------------

    public function test_partial_payment_posts_p7_and_marks_the_bill_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);

        $response = $this->pay($bill, [
            'amount' => 50000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertRedirect();

        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(50000, $bill->amount_paid);
        $this->assertSame(50000, $bill->balance);

        $entry = JournalEntry::query()->where('source_type', 'bill_payment')->sole();
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    public function test_multiple_partials_summing_to_the_full_balance_end_in_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);

        $this->pay($bill, ['amount' => 30000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();
        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(30000, $bill->amount_paid);

        $this->pay($bill, ['amount' => 30000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12'])->assertRedirect();
        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(60000, $bill->amount_paid);

        $this->pay($bill, ['amount' => 40000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-14'])->assertRedirect();
        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame(100000, $bill->amount_paid);
        $this->assertSame(0, $bill->balance);

        $this->assertSame(3, BillPayment::query()->count());
        $this->assertSame(3, JournalEntry::query()->where('source_type', 'bill_payment')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Overpayment rejected
    // ---------------------------------------------------------------

    public function test_overpayment_beyond_the_full_balance_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);

        $response = $this->pay($bill, [
            'amount' => 100001,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, BillPayment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill_payment')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(100000, $bill->balance);
        $this->assertLedgerBalances();
    }

    public function test_a_payment_exceeding_the_remaining_balance_after_a_partial_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);
        $this->pay($bill, ['amount' => 60000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])->assertRedirect();

        // Remaining balance is 40000 — 40001 must be rejected.
        $response = $this->pay($bill, ['amount' => 40001, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(1, BillPayment::query()->count(), 'Only the first (accepted) payment should exist.');

        $bill->refresh();
        $this->assertSame(BillStatus::PartiallyPaid, $bill->status);
        $this->assertSame(60000, $bill->amount_paid);
        $this->assertSame(40000, $bill->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Payment clears AP
    // ---------------------------------------------------------------

    public function test_full_payment_clears_the_vendors_ap_balance_back_to_zero(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);
        $category = $this->categoryAccount($company);

        // Real draft -> receive flow (BillService::receive, already covered
        // by BillReceiveTest) so there is a genuine P6 AP credit on the
        // books — not a hand-rolled ledger row — for the payment to
        // actually clear.
        $draft = $this->draftBill($company, $contact);
        $this->billLine($draft, $category, ['amount' => 75000, 'tax_rate_id' => null]);
        $this->receiveBillHttp($draft)->assertRedirect();
        $bill = $draft->fresh();

        $this->pay($bill, ['amount' => 75000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        $apBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ap']->id)
            ->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        $this->assertSame(0, (int) $apBalance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Concurrency (spec §9): overpayment guard on a lockForUpdate() read.
    //
    // A genuine two-connection race is deliberately NOT simulated here — same
    // RefreshDatabase deadlock/flakiness concern documented in
    // InvoicePaymentTest/IdempotencyTest/OpeningBalancesTest/BankAccountsTest
    // (fixture rows live inside RefreshDatabase's still-open, uncommitted
    // outer transaction on the default connection, so they're invisible to a
    // genuinely separate session — a second connection's lockForUpdate() on
    // the same row would just see "no such row" rather than actually
    // blocking on the lock). Instead: (1) assert the SELECT that record()
    // issues on the bill is actually a FOR UPDATE, which is what makes the
    // lock hold, and (2) prove the guard reads the LOCKED row's fresh state
    // by showing a second call, once the first has committed, correctly
    // sees the reduced balance and is rejected — exactly what would happen
    // to the loser of a real race once the winner's transaction commits and
    // releases the lock. Mirrors InvoicePaymentTest's own identical pair.
    // ---------------------------------------------------------------

    public function test_record_payment_locks_the_bill_row_with_a_locking_select(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);

        DB::enableQueryLog();
        $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue(
            $queries->contains(fn (string $q) => str_contains(strtolower($q), 'from `bills`')
                && str_contains(strtolower($q), 'for update')),
            'BillPaymentService::record must lock the bill row (lockForUpdate) before checking the outstanding balance.'
        );
    }

    public function test_second_concurrent_style_payment_after_the_first_commits_cannot_overpay(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 100000);

        // "Winner": a full payment for the whole balance.
        $this->pay($bill, ['amount' => 100000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();

        // "Loser": had it raced against a stale (pre-payment) balance read it
        // might have believed the bill still owed 100000 — but the locked
        // re-read inside the transaction sees the now-Paid, 0-balance row and
        // rejects it, exactly as the loser of a genuine race would once it
        // acquires the lock after the winner released it.
        $second = $this->pay($bill, ['amount' => 100000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-16']);

        $second->assertSessionHasErrors('status');
        $this->assertSame(1, BillPayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'bill_payment')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame(100000, $bill->amount_paid);
        $this->assertSame(0, $bill->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Guards: not-payable statuses
    // ---------------------------------------------------------------

    public function test_cannot_pay_a_draft_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
            'due_date' => '2026-08-01',
            'total' => 50000,
            'balance' => 50000,
        ]);

        $response = $this->pay($bill, ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, BillPayment::query()->count());
    }

    public function test_cannot_pay_a_paid_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000, [
            'status' => BillStatus::Paid->value,
            'amount_paid' => 50000,
            'balance' => 0,
        ]);

        $response = $this->pay($bill, ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, BillPayment::query()->count());
    }

    public function test_cannot_pay_a_void_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000, [
            'status' => BillStatus::Void->value,
            'voided_at' => now(),
        ]);

        $response = $this->pay($bill, ['amount' => 10000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, BillPayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_posts_exactly_one_payment_and_one_entry(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $bill = $this->receivedBill($company, $contact, 50000);

        $data = ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'];
        $first = $this->pay($bill, $data, $key);
        $second = $this->pay($bill, $data, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, BillPayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'bill_payment')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'bill_payment.recorded')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        // amount_paid counted exactly ONCE across both requests — the replay
        // short-circuits before the closure (and its amount_paid += amount
        // mutation) ever runs a second time.
        $this->assertSame(50000, $bill->amount_paid);
        $this->assertLedgerBalances();
    }

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->postJson("/bills/{$bill->id}/payments", [
            'amount' => 50000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, BillPayment::query()->count());
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
        $contact = $this->vendor($companyB);
        $this->chart($companyB);

        $bill = $this->receivedBill($companyB, $contact, 50000);

        $response = $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $foreignBank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, BillPayment::query()->count());
    }

    public function test_an_archived_bank_account_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company, ['is_archived' => true]);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, BillPayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Amount guard
    // ---------------------------------------------------------------

    public function test_a_zero_amount_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->pay($bill, ['amount' => 0, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, BillPayment::query()->count());
    }

    public function test_a_negative_amount_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->pay($bill, ['amount' => -100, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, BillPayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_paying_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-06-15']);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, BillPayment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill_payment')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertSame(0, $bill->amount_paid);
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
            $contact = $this->vendor($company);
            $this->chart($company);
            $bank = $this->bankAccount($company);

            $bill = $this->receivedBill($company, $contact, 50000);

            $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_recording_a_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->vendor($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $bill = $this->receivedBill($company, $contact, 50000);

        $response = $this->pay($bill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15']);

        $response->assertForbidden();
        $this->assertSame(0, BillPayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_bill_404s_on_payment(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->vendor($companyA);
        $this->chart($companyA);
        $foreignBill = $this->receivedBill($companyA, $contactA, 50000);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bank = $this->bankAccount($companyB);

        $this->pay($foreignBill, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15'])
            ->assertNotFound();
    }
}
