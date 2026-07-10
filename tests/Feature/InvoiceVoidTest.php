<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\EntryRole;
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
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 3 Task 5: voiding an invoice the accounting-correct way — reverse the
 * payments first (InvoicePaymentService::voidPayment, P2/P3 reversal), then
 * reverse the invoice itself (InvoiceService::void, posting rule P9 — a
 * mirror of P1). Every scenario here drives the REAL send()/recordPayment()
 * HTTP flows (never hand-rolled Sent/Paid rows) because void()/voidPayment()
 * both need a genuine primary JournalEntry on the books to reverse.
 */
class InvoiceVoidTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
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

    private function taxPayableAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
            'is_system' => true,
        ]);
    }

    /** @return array{ar: Account, taxPayable: Account} Standard chart this feature depends on (1100/2100). */
    private function chart(Company $company): array
    {
        return [
            'ar' => $this->arAccount($company),
            'taxPayable' => $this->taxPayableAccount($company),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
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
     * Wrapped in CompanyContext::runFor — see InvoiceSendTest for why
     * (InvoiceLine::booted()'s freeze guard needs a live CompanyContext).
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

    /** @param array<string, mixed> $data */
    private function payHttp(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function voidInvoiceHttp(Invoice $invoice, array $data = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/void", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidPaymentHttp(Invoice $invoice, InvoicePayment $payment, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments/{$payment->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Builds a genuine SENT invoice (real draft -> send flow) with one taxed
     * line: 100000 subtotal + 18% (18000) tax = 118000 total, always — every
     * caller in this file relies on this fixed total (payments below use
     * 118000/partial splits of it), so the tax rate is NOT optional here.
     */
    private function sentTaxedInvoice(Company $company, Contact $contact, Account $income): Invoice
    {
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id, 'rate' => '18.0000']);

        $invoice = $this->draftInvoice($company, $contact, ['issue_date' => '2026-07-01']);
        $this->invoiceLine($invoice, $income, [
            'quantity' => '1',
            'unit_price' => 100000,
            'tax_rate_id' => $taxRate->id,
        ]);
        $this->sendInvoiceHttp($invoice)->assertRedirect();

        return $invoice->fresh();
    }

    // ---------------------------------------------------------------
    // P9: void a sent, unpaid invoice — reverses P1 exactly
    // ---------------------------------------------------------------

    public function test_void_a_sent_unpaid_invoice_posts_p9_reversal_and_returns_ar_to_zero(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $primaryEntry = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Primary->value)->sole();

        $response = $this->voidInvoiceHttp($invoice, ['date' => '2026-07-15', 'memo' => 'Customer cancelled order']);

        $response->assertRedirect(route('invoices.show', $invoice->id));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertNotNull($invoice->voided_at);

        $reversal = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();

        $this->assertSame($primaryEntry->id, $reversal->reverses_entry_id);
        $this->assertSame('2026-07-15', $reversal->entry_date->toDateString());
        $this->assertCount(3, $reversal->lines);

        $arLine = $reversal->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);
        $this->assertSame(0, $arLine->debit);
        $this->assertSame(118000, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $incomeLine = $reversal->lines->firstWhere('account_id', $income->id);
        $this->assertNotNull($incomeLine);
        $this->assertSame(100000, $incomeLine->debit);
        $this->assertSame(0, $incomeLine->credit);

        $taxLine = $reversal->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertNotNull($taxLine);
        $this->assertSame(18000, $taxLine->debit);
        $this->assertSame(0, $taxLine->credit);

        $this->assertSame($reversal->lines->sum('debit'), $reversal->lines->sum('credit'));

        $arBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ar']->id)
            ->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $arBalance);

        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'invoice.voided')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame($reversal->id, $audit->after['reversal_entry_id']);
    }

    public function test_voiding_defaults_the_reversal_date_to_today_when_omitted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        $this->voidInvoiceHttp($invoice, ['memo' => 'No date supplied'])->assertRedirect();

        $reversal = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();

        $this->assertSame(CarbonImmutable::today()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // is_opening: friendly domain error, not a 404 (Phase 3 carry-in,
    // closed Phase 4 Task 6 — mirrors BillVoidTest's identical bill-side
    // coverage; see InvoiceIsOpening's own docblock).
    // ---------------------------------------------------------------

    public function test_voiding_an_is_opening_invoice_returns_a_friendly_opening_balances_error(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'currency_code' => 'PKR',
            'fx_rate' => 1,
            'total' => 50000,
            'balance' => 50000,
            'base_total' => 50000,
            'is_opening' => true,
            'sent_at' => now(),
        ]);

        $response = $this->voidInvoiceHttp($invoice, ['memo' => 'Attempt']);

        $response->assertSessionHasErrors('status');
        $message = session('errors')->first('status');
        $this->assertStringContainsString('Opening Balances', $message);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->voided_at);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Refused while a live payment exists
    // ---------------------------------------------------------------

    public function test_void_an_invoice_with_a_live_payment_is_refused_and_changes_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 50000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        $response = $this->voidInvoiceHttp($invoice, ['memo' => 'Attempted void with live payment']);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('payment', session('errors')->first('status'));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertNull($invoice->voided_at);

        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // voidPayment: reverses P2/P3, invoice returns to sent/partially_paid
    // ---------------------------------------------------------------

    public function test_void_payment_reverses_the_payment_and_returns_the_invoice_to_sent(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        $payment = InvoicePayment::query()->sole();
        $primaryPaymentEntry = JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Primary->value)->sole();

        $response = $this->voidPaymentHttp($invoice, $payment);

        $response->assertRedirect(route('invoices.show', $invoice->id));

        $payment->refresh();
        $this->assertSame('void', $payment->status);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(118000, $invoice->balance);

        $reversal = JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->sole();
        $this->assertSame($primaryPaymentEntry->id, $reversal->reverses_entry_id);

        $bankLine = $reversal->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(0, $bankLine->debit);
        $this->assertSame(118000, $bankLine->credit);

        $arLine = $reversal->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(118000, $arLine->debit);
        $this->assertSame(0, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'invoice.payment_voided')->sole();
        $this->assertSame($payment->id, $audit->subject_id);
        $this->assertSame($reversal->id, $audit->after['reversal_entry_id']);

        // Now the invoice void can proceed since there's no live payment left.
        $this->voidInvoiceHttp($invoice, ['memo' => 'Now voidable'])->assertRedirect();
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_void_payment_when_another_live_payment_remains_leaves_the_invoice_partially_paid(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 60000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $this->payHttp($invoice, ['amount' => 58000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-11'])
            ->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        $payments = InvoicePayment::query()->orderBy('id')->get();
        $second = $payments->last();

        $this->voidPaymentHttp($invoice, $second)->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(60000, $invoice->amount_paid);
        $this->assertSame(58000, $invoice->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Void a fully-paid invoice: void its payment first, then void succeeds
    // ---------------------------------------------------------------

    public function test_voiding_a_paid_invoice_requires_voiding_its_payment_first_then_succeeds(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        // Direct void is refused while the payment is still live.
        $this->voidInvoiceHttp($invoice, ['memo' => 'Attempt'])->assertSessionHasErrors('status');

        $payment = InvoicePayment::query()->sole();
        $this->voidPaymentHttp($invoice, $payment)->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $this->voidInvoiceHttp($invoice, ['memo' => 'Now voidable'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);

        $arBalance = DB::table('journal_lines')
            ->where('account_id', $chart['ar']->id)->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $arBalance);

        $bankBalance = DB::table('journal_lines')
            ->where('account_id', $bank->ledger_account_id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
        $this->assertSame(0, (int) $bankBalance);

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Second void / second voidPayment rejected with a friendly message
    // ---------------------------------------------------------------

    public function test_second_void_of_the_same_invoice_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        $this->voidInvoiceHttp($invoice, ['memo' => 'First void'])->assertRedirect();

        $response = $this->voidInvoiceHttp($invoice->fresh(), ['memo' => 'Second void attempt']);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been voided', session('errors')->first('status'));

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    public function test_second_void_payment_of_the_same_payment_is_rejected_with_a_friendly_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->voidPaymentHttp($invoice, $payment)->assertRedirect();

        $response = $this->voidPaymentHttp($invoice, $payment->fresh());

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been reversed', session('errors')->first('status'));

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Locked period: reversal posts in an OPEN period even when the
    // original P1 is in a locked one; a reversal date IN a locked period 422s.
    // ---------------------------------------------------------------

    public function test_reversal_posts_in_an_open_period_when_the_original_entry_is_in_a_locked_period(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        // Sent while unlocked.
        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        // Lock the books through a date covering the original issue_date
        // (2026-07-01), but the reversal date (2026-07-20) stays open.
        $company->forceFill(['books_locked_through' => '2026-07-05'])->save();
        // SetCompanyContext resolves the current company off $user->currentCompany,
        // a BelongsTo relation Eloquent caches on the (shared, actingAs'd) $user
        // object once loaded by the FIRST request above — the save() just above
        // mutated a DIFFERENT Company instance, so without unsetting the cached
        // relation the next request would still see the pre-lock row.
        $user->unsetRelation('currentCompany');

        $response = $this->voidInvoiceHttp($invoice, ['date' => '2026-07-20', 'memo' => 'Void into an open period']);

        $response->assertRedirect();
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->assertLedgerBalances();
    }

    public function test_a_reversal_date_inside_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        $company->forceFill(['books_locked_through' => '2026-07-31'])->save();
        // See the sibling "open period" test above for why this is required.
        $user->unsetRelation('currentCompany');

        $response = $this->voidInvoiceHttp($invoice, ['date' => '2026-07-15', 'memo' => 'Void into a locked period']);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('locked', session('errors')->first('status'));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_void_posts_exactly_one_reversal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        $first = $this->voidInvoiceHttp($invoice, ['memo' => 'Void'], $key);
        $second = $this->voidInvoiceHttp($invoice, ['memo' => 'Void'], $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.voided')->count());
        $this->assertLedgerBalances();
    }

    public function test_idempotent_double_submit_void_payment_posts_exactly_one_reversal(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $first = $this->voidPaymentHttp($invoice, $payment, $key);
        $second = $this->voidPaymentHttp($invoice, $payment, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->where('entry_role', EntryRole::Reversal->value)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.payment_voided')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: invoices.void — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_void_an_invoice_and_a_payment(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->customer($company);
            $this->chart($company);
            $income = $this->incomeAccount($company);
            $bank = $this->bankAccount($company);

            $invoice = $this->sentTaxedInvoice($company, $contact, $income);
            $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
                ->assertRedirect();
            $payment = InvoicePayment::query()->where('invoice_id', $invoice->id)->sole();

            $this->voidPaymentHttp($invoice, $payment)->assertRedirect();
            $this->voidInvoiceHttp($invoice->fresh(), ['memo' => 'Void'])->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_voiding_an_invoice_or_a_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        // Build the sent+paid invoice as Owner first (Staff can't send/pay),
        // then switch to Staff to attempt the void actions.
        $owner = User::factory()->create();
        $owner->companies()->attach($company, ['role' => Role::Owner->value]);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($owner);
        $invoice = $this->sentTaxedInvoice($company, $contact, $income);
        $this->payHttp($invoice, ['amount' => 118000, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $payment = InvoicePayment::query()->sole();

        $this->actingAsRole($company, Role::Staff);

        $this->voidPaymentHttp($invoice, $payment)->assertForbidden();
        $this->voidInvoiceHttp($invoice->fresh(), ['memo' => 'Void'])->assertForbidden();

        $this->assertSame('posted', $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_invoice_404s_on_void(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $incomeA = $this->incomeAccount($companyA);

        $ownerA = User::factory()->create();
        $ownerA->companies()->attach($companyA, ['role' => Role::Owner->value]);
        $ownerA->forceFill(['current_company_id' => $companyA->id])->save();
        $this->actingAs($ownerA);
        $foreignInvoice = $this->sentTaxedInvoice($companyA, $contactA, $incomeA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->voidInvoiceHttp($foreignInvoice, ['memo' => 'Void'])->assertNotFound();
    }

    public function test_a_foreign_payment_404s_on_void_payment(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $bankA = $this->bankAccount($companyA);

        $ownerA = User::factory()->create();
        $ownerA->companies()->attach($companyA, ['role' => Role::Owner->value]);
        $ownerA->forceFill(['current_company_id' => $companyA->id])->save();
        $this->actingAs($ownerA);
        $foreignInvoice = $this->sentTaxedInvoice($companyA, $contactA, $incomeA);
        $this->payHttp($foreignInvoice, ['amount' => 118000, 'bank_account_id' => $bankA->id, 'payment_date' => '2026-07-10'])
            ->assertRedirect();
        $foreignPayment = InvoicePayment::query()->sole();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $contactB = $this->customer($companyB);
        $this->chart($companyB);
        $incomeB = $this->incomeAccount($companyB);
        // An invoice that DOES belong to company B, so the 404 is specifically
        // about the payment not belonging to it (not just the invoice).
        $invoiceB = $this->sentTaxedInvoice($companyB, $contactB, $incomeB);

        $this->voidPaymentHttp($invoiceB, $foreignPayment)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Idempotency key required
    // ---------------------------------------------------------------

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing_on_void(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->sentTaxedInvoice($company, $contact, $income);

        $response = $this->postJson("/invoices/{$invoice->id}/void", ['memo' => 'Void']);

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }
}
