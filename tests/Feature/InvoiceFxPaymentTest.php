<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Posting rule P2fx (spec §17.1, Phase 3 Task 8) — payment against a
 * foreign-currency invoice: the user enters the ACTUAL base (PKR) amount the
 * bank credited; AR is relieved at the INVOICE's own fx_rate (not this
 * payment's implied rate); the difference is a realized FX gain (4950) or
 * loss (5950); the payment that brings the document balance to exactly 0
 * relieves whatever base AR is still actually outstanding rather than
 * round(rate x amount) — the "settlement rounding" rule — so base AR always
 * zeroes out exactly, no matter how much rounding residue independently-
 * rounded partials accumulated along the way.
 *
 * Mirrors InvoicePaymentTest's (Task 4, domestic) and InvoiceFxSendTest's
 * (Task 7, P1fx) helper shapes/fixtures.
 */
class InvoiceFxPaymentTest extends TestCase
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

    private function fxGainAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4950',
            'name' => 'FX Gain',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
            'is_system' => true,
        ]);
    }

    private function fxLossAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5950',
            'name' => 'FX Loss',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'is_system' => true,
        ]);
    }

    /** @return array{ar: Account, taxPayable: Account, fxGain: Account, fxLoss: Account} Standard chart this feature depends on. */
    private function chart(Company $company): array
    {
        return [
            'ar' => $this->arAccount($company),
            'taxPayable' => $this->taxPayableAccount($company),
            'fxGain' => $this->fxGainAccount($company),
            'fxLoss' => $this->fxLossAccount($company),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * Hand-crafts an already-SENT USD invoice directly (bypassing
     * InvoiceService::send — that flow is InvoiceFxSendTest's concern),
     * consistent with a fresh P1fx send: balance === total, amount_paid ===
     * 0. Used by tests that only need to exercise the PAYMENT-side posting
     * (P2fx) in isolation, not a genuine originating P1fx ledger entry.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sentFxInvoice(Company $company, Contact $contact, int $total, string $fxRate, int $baseTotal, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'issue_date' => '2026-07-01',
            'currency_code' => 'USD',
            'fx_rate' => $fxRate,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
            'base_subtotal' => $baseTotal,
            'base_tax_total' => 0,
            'base_total' => $baseTotal,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function usdDraftInvoice(Company $company, Contact $contact, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => 'USD',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor — see InvoiceSendTest's identical
     * helper docblock for why (InvoiceLine::booted()'s freeze guard needs a
     * live CompanyContext on every create).
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

    /** @param array<string, mixed> $body */
    private function sendInvoice(Invoice $invoice, ?string $key = null, array $body = []): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/send", $body, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function pay(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidPayment(Invoice $invoice, InvoicePayment $payment, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments/{$payment->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** Net (debit - credit) journal_lines balance for one account, scoped to one contact — mirrors InvoicePaymentTest's "clears AR" query. */
    private function contactAccountBalance(Account $account, Contact $contact): int
    {
        return (int) DB::table('journal_lines')
            ->where('account_id', $account->id)
            ->where('contact_id', $contact->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');
    }

    /** Net (credit - debit) journal_lines balance for a credit-normal account, company-wide (no contact). */
    private function creditNormalBalance(Account $account): int
    {
        return (int) DB::table('journal_lines')
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net');
    }

    // ---------------------------------------------------------------
    // P2fx: the spec §17.1 worked example, exactly.
    // USD 1,000 invoice @ 280 (base_total 28,000,000 = PKR 280,000.00), paid
    // in FULL, bank credits base_amount 28,500,000 (PKR 285,000.00) ->
    // Dr Bank 28,500,000 / Cr AR 28,000,000 / Cr FX Gain 500,000 (PKR 5,000).
    // ---------------------------------------------------------------

    public function test_p2fx_posts_the_spec_worked_example_exactly(): void
    {
        $company = Company::factory()->create(); // base_currency PKR
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100000,
            'base_amount' => 28500000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice->id));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(100000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->balance);

        $payment = InvoicePayment::query()->sole();
        $this->assertSame(100000, $payment->amount);
        $this->assertSame(28500000, $payment->base_amount);
        $this->assertSame(28000000, $payment->base_ar_relief);
        // Implied rate (285), shown to the user, never fed back into posting.
        $this->assertSame('285.00000000', $payment->fx_rate);

        $entry = JournalEntry::query()
            ->where('source_type', 'invoice_payment')->where('source_id', $payment->id)
            ->sole();
        $this->assertCount(3, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(28500000, $bankLine->debit);
        $this->assertSame(0, $bankLine->credit);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);
        $this->assertSame(0, $arLine->debit);
        $this->assertSame(28000000, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $gainLine = $entry->lines->firstWhere('account_id', $chart['fxGain']->id);
        $this->assertNotNull($gainLine);
        $this->assertSame(0, $gainLine->debit);
        $this->assertSame(500000, $gainLine->credit); // PKR 5,000.00

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Loss case: bank credits LESS than the invoice-rate AR -> Dr 5950.
    // ---------------------------------------------------------------

    public function test_p2fx_loss_case_posts_5950_when_the_bank_credits_less_than_the_invoice_rate_ar(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $this->pay($invoice, [
            'amount' => 100000,
            'base_amount' => 27500000, // PKR 275,000.00 -- less than the 280,000 AR relief
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $payment = InvoicePayment::query()->sole();
        $this->assertSame(27500000, $payment->base_amount);
        $this->assertSame(28000000, $payment->base_ar_relief);

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $this->assertCount(3, $entry->lines);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertSame(27500000, $bankLine->debit);

        $lossLine = $entry->lines->firstWhere('account_id', $chart['fxLoss']->id);
        $this->assertNotNull($lossLine);
        $this->assertSame(500000, $lossLine->debit); // PKR 5,000.00
        $this->assertSame(0, $lossLine->credit);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(28000000, $arLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);
    }

    // ---------------------------------------------------------------
    // Settlement rounding property (the subtle one): three payments where
    // per-payment rounding accumulates a residue, and the FINAL payment
    // relieves the ACTUAL remaining base AR (not round(rate x amount)), so
    // base AR for the invoice's contact ends up EXACTLY 0 -- no phantom
    // paisa.
    //
    // fx_rate 1.005, doc total 300 (3 x 100 partials). Component-wise send:
    // base_total = round_half_up(300 x 1.005) = round_half_up(301.5) = 302.
    // Each 100-unit partial independently rounds to round_half_up(100 x
    // 1.005) = round_half_up(100.5) = 101. Two partials of 101 = 202,
    // leaving only 100 of REAL base AR outstanding -- if the third (final)
    // payment naively rounded too, it would relieve 101, over-crediting AR
    // by 1 and leaving a -1 phantom balance. The settlement-rounding rule
    // instead relieves exactly the remaining 100.
    // ---------------------------------------------------------------

    public function test_settlement_rounding_the_final_payment_zeroes_base_ar_exactly_despite_partial_rounding_residue(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 300, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, null, ['fx_rate' => '1.005'])->assertRedirect();
        $invoice = $invoice->fresh();

        $this->assertSame(300, $invoice->total);
        $this->assertSame(302, $invoice->base_total);

        // The naive per-payment rounding a partial of 100 would produce --
        // used below to prove the FINAL payment does NOT use it.
        $naivePartialRelief = 101;

        $first = $this->pay($invoice, [
            'amount' => 100, 'base_amount' => 101, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10',
        ]);
        $first->assertRedirect();
        $firstPayment = InvoicePayment::query()->orderBy('id')->first();
        $this->assertSame($naivePartialRelief, $firstPayment->base_ar_relief);

        $second = $this->pay($invoice, [
            'amount' => 100, 'base_amount' => 101, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-12',
        ]);
        $second->assertRedirect();
        $secondPayment = InvoicePayment::query()->orderBy('id')->skip(1)->first();
        $this->assertSame($naivePartialRelief, $secondPayment->base_ar_relief);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(100, $invoice->balance);

        // FINAL payment: brings the doc balance to exactly 0.
        $third = $this->pay($invoice, [
            'amount' => 100, 'base_amount' => 100, 'bank_account_id' => $bank->id, 'payment_date' => '2026-07-14',
        ]);
        $third->assertRedirect();
        $thirdPayment = InvoicePayment::query()->orderBy('id')->skip(2)->first();

        // The settlement-rounding property: 100, not the naive 101 --
        // base_total (302) minus the two prior reliefs (101 + 101) = 100.
        $this->assertSame(100, $thirdPayment->base_ar_relief);
        $this->assertNotSame($naivePartialRelief, $thirdPayment->base_ar_relief);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);
        $this->assertSame(300, $invoice->amount_paid);

        // The real proof: base AR for this invoice's contact is EXACTLY 0.
        $this->assertSame(0, $this->contactAccountBalance($chart['ar'], $contact));

        $this->assertSame(3, InvoicePayment::query()->count());
        $this->assertSame(3, JournalEntry::query()->where('source_type', 'invoice_payment')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Void-then-refinal: the settlement-rounding "remaining base AR" sum
    // filters status != 'void', so a VOIDED partial's relief must NOT be
    // subtracted when a later payment goes final. This proves the
    // load-bearing void filter in resolveBaseArRelief()'s Σ-prior query.
    //
    // Real send flow (genuine 28,000,000 AR debit) so "base AR zeroes"
    // actually means something. USD 1,000 @ 280. Record a partial (40,000
    // USD -> base_ar_relief round_half_up(280 x 40000) = 11,200,000), VOID
    // it (its P2fx entry is reversed: the Cr AR 11,200,000 is cancelled by
    // a Dr AR 11,200,000, net 0), then pay the invoice to FINAL. Because
    // the voided payment is excluded from the prior-relief sum, the final
    // relief is the FULL base_total (28,000,000 - 0), not 28,000,000 minus
    // the voided 11,200,000 (= 16,800,000, which would leave a phantom
    // 11,200,000 of AR behind). base AR nets to exactly 0.
    // ---------------------------------------------------------------

    public function test_a_voided_partial_is_excluded_from_the_final_payments_remaining_base_ar_sum(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => null]);
        $this->sendInvoice($invoice, null, ['fx_rate' => '280'])->assertRedirect();
        $invoice = $invoice->fresh();
        $this->assertSame(28000000, $invoice->base_total);

        // Partial FX payment: USD 400 of the USD 1,000, bank credits an
        // arbitrary 11,300,000 (a small gain vs. the 11,200,000 relief).
        $this->pay($invoice, [
            'amount' => 40000, 'base_amount' => 11300000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-10',
        ])->assertRedirect();

        $partial = InvoicePayment::query()->sole();
        $this->assertSame(11200000, $partial->base_ar_relief); // round_half_up(280 x 40000)

        // VOID the partial — invoice returns to Sent, balance back to full.
        $this->voidPayment($invoice, $partial)->assertRedirect();
        $partial->refresh();
        $this->assertSame('void', $partial->status);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(100000, $invoice->balance);

        // Pay to FINAL (the whole doc balance). Bank credits 28,600,000 (a
        // 600,000 gain over the 28,000,000 relief).
        $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28600000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-14',
        ])->assertRedirect();

        $final = InvoicePayment::query()->where('status', 'posted')->sole();

        // The proof: the voided partial's 11,200,000 relief is EXCLUDED, so
        // the final relief is the full base_total, not base_total minus the
        // voided relief.
        $this->assertSame(28000000, $final->base_ar_relief);
        $this->assertNotSame(16800000, $final->base_ar_relief); // == 28,000,000 - 11,200,000, the broken-filter result

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);

        // base AR for the contact nets to EXACTLY 0: send Dr 28,000,000;
        // partial Cr 11,200,000 then its void Dr 11,200,000 (net 0); final
        // Cr 28,000,000.
        $this->assertSame(0, $this->contactAccountBalance($chart['ar'], $contact));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Tax Payable is a fixed base-currency liability from send (spec
    // §17.1) -- the payment's FX plug must never touch it, even when a
    // gain/loss line posts alongside the AR credit.
    // ---------------------------------------------------------------

    public function test_tax_payable_is_untouched_by_a_payment_and_its_fx_plug(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, [
            'quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => $eighteenPercent->id,
        ]);
        $this->sendInvoice($invoice, null, ['fx_rate' => '280'])->assertRedirect();
        $invoice = $invoice->fresh();

        $this->assertSame(5040000, $invoice->base_tax_total);
        $taxBalanceBefore = $this->creditNormalBalance($chart['taxPayable']);
        $this->assertSame(5040000, $taxBalanceBefore);

        // Full payment, with a genuine gain, so the FX plug actually posts.
        $this->pay($invoice, [
            'amount' => 118000, // full doc balance
            'base_amount' => 33540000, // 33,040,000 AR relief + 500,000 gain
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $this->assertCount(3, $entry->lines); // bank + AR + gain, no tax line
        $this->assertNull($entry->lines->firstWhere('account_id', $chart['taxPayable']->id));

        $taxBalanceAfter = $this->creditNormalBalance($chart['taxPayable']);
        $this->assertSame($taxBalanceBefore, $taxBalanceAfter);
        $this->assertSame(5040000, $taxBalanceAfter);

        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Domestic invoice payment unchanged (Task 4 path): base_amount
    // defaults to amount, no FX line, fx_rate stays null.
    // ---------------------------------------------------------------

    public function test_domestic_invoice_payment_is_unchanged_from_task_4(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-0001',
            'currency_code' => 'PKR',
            'fx_rate' => 1,
            'subtotal' => 118000,
            'tax_total' => 0,
            'total' => 118000,
            'amount_paid' => 0,
            'balance' => 118000,
            'base_subtotal' => 118000,
            'base_tax_total' => 0,
            'base_total' => 118000,
        ]);

        // No base_amount in the request at all -- must default server-side.
        $this->pay($invoice, [
            'amount' => 118000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $payment = InvoicePayment::query()->sole();
        $this->assertSame(118000, $payment->amount);
        $this->assertSame(118000, $payment->base_amount);
        $this->assertSame(118000, $payment->base_ar_relief);
        $this->assertNull($payment->fx_rate);

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $this->assertCount(2, $entry->lines); // no FX line

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(118000, $arLine->credit);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->balance);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Overpayment is still checked in DOCUMENT currency.
    // ---------------------------------------------------------------

    public function test_overpayment_in_document_currency_is_rejected_for_an_fx_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100001, // 1 minor unit over the doc balance
            'base_amount' => 28500280,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice_payment')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // base_amount: required for a foreign-currency invoice, >= 1.
    // ---------------------------------------------------------------

    public function test_base_amount_is_required_for_a_foreign_currency_invoice_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100000,
            // base_amount omitted entirely
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('base_amount');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    public function test_a_zero_base_amount_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100000,
            'base_amount' => 0,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ]);

        $response->assertSessionHasErrors('base_amount');
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_submit_of_an_fx_payment_posts_exactly_once(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $data = [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ];
        $this->pay($invoice, $data, $key)->assertRedirect();
        $this->pay($invoice, $data, $key)->assertRedirect();

        $this->assertSame(1, InvoicePayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice_payment')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_paying_an_fx_invoice_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-06-15',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, InvoicePayment::query()->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate: payments.record — Staff forbidden (spot check for the FX path).
    // ---------------------------------------------------------------

    public function test_staff_is_forbidden_from_recording_an_fx_payment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $this->chart($company);
        $bank = $this->bankAccount($company);

        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $response = $this->pay($invoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, InvoicePayment::query()->count());
    }

    // ---------------------------------------------------------------
    // Scoped 404 (spot check for the FX path).
    // ---------------------------------------------------------------

    public function test_a_foreign_company_fx_invoice_404s_on_payment(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $foreignInvoice = $this->sentFxInvoice($companyA, $contactA, 100000, '280', 28000000);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bank = $this->bankAccount($companyB);

        $this->pay($foreignInvoice, [
            'amount' => 100000, 'base_amount' => 28500000,
            'bank_account_id' => $bank->id, 'payment_date' => '2026-07-15',
        ])->assertNotFound();
    }
}
