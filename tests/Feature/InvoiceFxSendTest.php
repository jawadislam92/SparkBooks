<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Posting rule P1fx (spec §17.1) — foreign-currency invoice send. The
 * company's base currency is always PKR (CompanyFactory default); every
 * invoice here is created with currency_code USD unless noted otherwise, so
 * InvoiceService::send's FX branch (extended in Phase 3 Task 7) is exercised
 * throughout. Mirrors InvoiceSendTest's helper shapes/fixtures.
 */
class InvoiceFxSendTest extends TestCase
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
    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
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

    // ---------------------------------------------------------------
    // P1fx: the spec §17.1 worked example, exactly.
    // USD 1,000.00 + 18% tax @ fx_rate 280 -> Dr AR 330,400 / Cr Income
    // 280,000 / Cr Tax Payable 50,400 (all PKR, base minor units).
    // ---------------------------------------------------------------

    public function test_p1fx_posts_the_spec_worked_example_exactly(): void
    {
        $company = Company::factory()->create(); // base_currency PKR (factory default)
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $invoice = $this->usdDraftInvoice($company, $contact, ['issue_date' => '2026-07-01']);
        $line = $this->invoiceLine($invoice, $income, [
            'quantity' => '1',
            'unit_price' => 100000, // USD 1,000.00
            'tax_rate_id' => $eighteenPercent->id,
        ]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => '280']);

        $response->assertRedirect(route('invoices.edit', $invoice->id));

        $invoice->refresh();
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertSame('280.00000000', $invoice->fx_rate);

        // Document-currency amounts: exactly as entered/computed in USD —
        // never touched by the FX conversion.
        $this->assertSame(100000, $invoice->subtotal);
        $this->assertSame(18000, $invoice->tax_total);
        $this->assertSame(118000, $invoice->total);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(118000, $invoice->balance);

        // Base (PKR) mirrors — the spec's own numbers, exactly.
        $this->assertSame(28000000, $invoice->base_subtotal); // PKR 280,000.00
        $this->assertSame(5040000, $invoice->base_tax_total); // PKR 50,400.00
        $this->assertSame(33040000, $invoice->base_total); // PKR 330,400.00

        $entry = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->sole();
        $this->assertCount(3, $entry->lines);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);
        $this->assertSame(33040000, $arLine->debit);
        $this->assertSame(0, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $incomeLine = $entry->lines->firstWhere('account_id', $income->id);
        $this->assertNotNull($incomeLine);
        $this->assertSame(0, $incomeLine->debit);
        $this->assertSame(28000000, $incomeLine->credit);

        $taxLine = $entry->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertNotNull($taxLine);
        $this->assertSame(0, $taxLine->debit);
        $this->assertSame(5040000, $taxLine->credit);
        // Tax Payable is now a base-currency liability equal exactly to
        // base_tax_total (spec §17.1: fixed at send, never re-measured).
        $this->assertSame($invoice->base_tax_total, $taxLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $line->refresh();
        $this->assertSame('18.0000', $line->tax_rate_snapshot);
        $this->assertSame(100000, $line->line_subtotal);
        $this->assertSame(18000, $line->line_tax);

        $audit = ActivityLog::query()->where('action', 'invoice.sent')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame('INV-0001', $audit->after['number']);
    }

    // ---------------------------------------------------------------
    // Conversion rounding: component-wise, never a single round(total x rate)
    // shot — the rounding trap the spec warns against (§17.1).
    // ---------------------------------------------------------------

    public function test_multi_line_fx_invoice_uses_component_wise_conversion_not_naive_total_rounding(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $incomeA = $this->incomeAccount($company, ['code' => '4000']);
        $incomeB = $this->incomeAccount($company, ['code' => '4010']);

        // fx_rate 1.005; two USD 0.50 lines (50 doc minor each), untaxed.
        // Component-wise: round_half_up(50 x 1.005) = round_half_up(50.25) = 50, twice -> base_subtotal 100.
        // Naive (WRONG) path: round_half_up(100 x 1.005) = round_half_up(100.5) = 101 -- must NOT be what gets posted.
        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $incomeA, ['quantity' => '1', 'unit_price' => 50, 'tax_rate_id' => null]);
        $this->invoiceLine($invoice, $incomeB, ['quantity' => '1', 'unit_price' => 50, 'tax_rate_id' => null]);

        $this->sendInvoice($invoice, null, ['fx_rate' => '1.005'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(100, $invoice->subtotal); // doc currency, unaffected
        $this->assertSame(0, $invoice->tax_total);
        $this->assertSame(100, $invoice->total);

        // The component-wise sum (100), NOT the naive total-rounding result (101).
        $this->assertSame(100, $invoice->base_subtotal);
        $this->assertSame(100, $invoice->base_total);
        $this->assertNotSame(101, $invoice->base_subtotal);

        $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();
        $this->assertCount(3, $entry->lines); // AR + 2 income lines, no tax

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(100, $arLine->debit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $incomeALine = $entry->lines->firstWhere('account_id', $incomeA->id);
        $this->assertSame(50, $incomeALine->credit);
        $incomeBLine = $entry->lines->firstWhere('account_id', $incomeB->id);
        $this->assertSame(50, $incomeBLine->credit);

        // The entry balances by construction: AR (100) = Σ income credits (50 + 50).
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // fx_rate required for a foreign-currency invoice
    // ---------------------------------------------------------------

    public function test_fx_rate_required_when_currency_is_foreign_and_missing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice); // no fx_rate in body

        $response->assertSessionHasErrors('fx_rate');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertLedgerBalances();
    }

    public function test_a_zero_fx_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => '0']);

        $response->assertSessionHasErrors('fx_rate');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_a_negative_fx_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => '-280']);

        $response->assertSessionHasErrors('fx_rate');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_a_non_numeric_fx_rate_is_rejected_with_a_clean_422_not_a_500(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => 'abc']);

        $response->assertSessionHasErrors('fx_rate');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    /**
     * The regression this fix closes: a plausible-but-malformed rate with 9
     * decimal places passes a naive shape check but exceeds fx_rate's
     * DECIMAL(16,8) precision. Before the fix, resolveFxRate let it through
     * and FxConverter::toBase threw a raw InvalidArgumentException deep inside
     * convertToBase() — which, not being an InvoiceSendException, escaped the
     * controller's catch as an uncaught 500. It must now be a clean 422 on
     * the fx_rate field, with nothing posted and no number burned.
     */
    public function test_an_fx_rate_with_more_than_eight_decimals_is_rejected_with_a_clean_422_not_a_500(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => '280.123456789']); // 9 dp

        $response->assertSessionHasErrors('fx_rate');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertLedgerBalances();

        // No number was burned by the failed attempt: a fresh, valid invoice
        // sent afterwards still gets INV-0001.
        $valid = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($valid, $income, ['quantity' => '1', 'unit_price' => 5000]);
        $this->sendInvoice($valid, null, ['fx_rate' => '280'])->assertRedirect();
        $this->assertSame('INV-0001', $valid->fresh()->number);
    }

    /**
     * The boundary case: a rate at exactly 8 decimal places is valid (it fits
     * DECIMAL(16,8)) and must send successfully — the >8dp rejection above
     * must not be off-by-one and reject a legitimate 8dp rate.
     */
    public function test_an_fx_rate_with_exactly_eight_decimals_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $this->sendInvoice($invoice, null, ['fx_rate' => '280.12345678'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame('280.12345678', $invoice->fx_rate);
        // round_half_up(100000 x 280.12345678) = round_half_up(28012345.678) = 28012346.
        $this->assertSame(28012346, $invoice->base_subtotal);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Domestic invoice: fx_rate is always exactly 1.0, base = doc — even if
    // an (irrelevant) fx_rate is supplied in the request body, it's ignored.
    // ---------------------------------------------------------------

    public function test_domestic_invoice_ignores_any_supplied_fx_rate_and_keeps_fx_rate_one(): void
    {
        $company = Company::factory()->create(); // base_currency PKR
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => 'PKR', // matches base_currency
        ]);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        // A stray fx_rate in the body must be ignored for a domestic invoice.
        $this->sendInvoice($invoice, null, ['fx_rate' => '999'])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('1.00000000', $invoice->fx_rate);
        $this->assertSame($invoice->subtotal, $invoice->base_subtotal);
        $this->assertSame($invoice->tax_total, $invoice->base_tax_total);
        $this->assertSame($invoice->total, $invoice->base_total);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Gate / scoped / idempotent — inherited from send(), spot-checked here
    // for the FX path specifically.
    // ---------------------------------------------------------------

    public function test_staff_is_forbidden_from_sending_an_fx_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $response = $this->sendInvoice($invoice, null, ['fx_rate' => '280']);

        $response->assertForbidden();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_a_foreign_company_fx_invoice_404s_on_send(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $foreignInvoice = $this->usdDraftInvoice($companyA, $contactA);
        $this->invoiceLine($foreignInvoice, $incomeA, ['quantity' => '1', 'unit_price' => 100000]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->sendInvoice($foreignInvoice, null, ['fx_rate' => '280'])->assertNotFound();
    }

    public function test_idempotent_double_send_of_an_fx_invoice_posts_exactly_one_entry(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->usdDraftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $first = $this->sendInvoice($invoice, $key, ['fx_rate' => '280']);
        $second = $this->sendInvoice($invoice, $key, ['fx_rate' => '280']);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertSame('INV-0001', $invoice->fresh()->number);
        $this->assertSame(28000000, $invoice->fresh()->base_total);
        $this->assertLedgerBalances();
    }
}
