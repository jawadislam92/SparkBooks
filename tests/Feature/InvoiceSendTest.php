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

class InvoiceSendTest extends TestCase
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
     * re-queries `Invoice::query()->find($line->invoice_id)` on every create
     * (see InvoiceLine.php), and that query applies BelongsToCompany's global
     * scope — which needs a live CompanyContext even though `company_id`
     * itself is passed explicitly here. Mirrors OpeningBalancesTest's
     * `account()`/`bankAccount()` helpers.
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

    private function sendInvoice(Invoice $invoice, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/send", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // P1: Dr AR / Cr income / Cr Tax Payable — the spec §6 worked example
    // ---------------------------------------------------------------

    public function test_send_posts_p1_exactly_for_the_spec_worked_example(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $invoice = $this->draftInvoice($company, $contact, ['issue_date' => '2026-07-01']);
        $line = $this->invoiceLine($invoice, $income, [
            'quantity' => '1',
            'unit_price' => 100000,
            'tax_rate_id' => $eighteenPercent->id,
        ]);

        $response = $this->sendInvoice($invoice);

        $response->assertRedirect(route('invoices.show', $invoice->id));

        $invoice->refresh();
        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertSame(100000, $invoice->subtotal);
        $this->assertSame(18000, $invoice->tax_total);
        $this->assertSame(118000, $invoice->total);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(118000, $invoice->balance);
        $this->assertSame(100000, $invoice->base_subtotal);
        $this->assertSame(18000, $invoice->base_tax_total);
        $this->assertSame(118000, $invoice->base_total);
        $this->assertSame('1.00000000', $invoice->fx_rate);

        $entry = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->sole();
        $this->assertCount(3, $entry->lines);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertNotNull($arLine);
        $this->assertSame(118000, $arLine->debit);
        $this->assertSame(0, $arLine->credit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $incomeLine = $entry->lines->firstWhere('account_id', $income->id);
        $this->assertNotNull($incomeLine);
        $this->assertSame(0, $incomeLine->debit);
        $this->assertSame(100000, $incomeLine->credit);

        $taxLine = $entry->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertNotNull($taxLine);
        $this->assertSame(0, $taxLine->debit);
        $this->assertSame(18000, $taxLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $line->refresh();
        $this->assertSame('18.0000', $line->tax_rate_snapshot);
        $this->assertSame(100000, $line->line_subtotal);
        $this->assertSame(18000, $line->line_tax);

        $audit = ActivityLog::query()->where('action', 'invoice.sent')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame('INV-0001', $audit->after['number']);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    public function test_a_no_tax_invoice_posts_only_ar_and_income_no_tax_payable_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 50000, 'tax_rate_id' => null]);

        $this->sendInvoice($invoice)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account_id', $chart['taxPayable']->id));

        $invoice->refresh();
        $this->assertSame(50000, $invoice->total);
        $this->assertSame(0, $invoice->tax_total);
        $this->assertLedgerBalances();
    }

    public function test_multi_line_mixed_taxed_and_untaxed_ar_equals_sum_of_income_plus_tax(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $incomeA = $this->incomeAccount($company, ['code' => '4000']);
        $incomeB = $this->incomeAccount($company, ['code' => '4010']);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $invoice = $this->draftInvoice($company, $contact);
        // 3 x 3333 = 9999 subtotal; 18% of 9999 = 1799.82 -> half-up 1800 tax.
        $this->invoiceLine($invoice, $incomeA, ['quantity' => '3', 'unit_price' => 3333, 'tax_rate_id' => $eighteenPercent->id]);
        // 2.5 x 10000 = 25000 subtotal; untaxed.
        $this->invoiceLine($invoice, $incomeB, ['quantity' => '2.5', 'unit_price' => 10000, 'tax_rate_id' => null]);

        $this->sendInvoice($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame(34999, $invoice->subtotal);
        $this->assertSame(1800, $invoice->tax_total);
        $this->assertSame(36799, $invoice->total);

        $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();
        $this->assertCount(4, $entry->lines);

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(36799, $arLine->debit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $incomeALine = $entry->lines->firstWhere('account_id', $incomeA->id);
        $this->assertSame(9999, $incomeALine->credit);

        $incomeBLine = $entry->lines->firstWhere('account_id', $incomeB->id);
        $this->assertSame(25000, $incomeBLine->credit);

        $taxLine = $entry->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertSame(1800, $taxLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // $0-value lines: a free/promo line (unit_price 0, allowed by
    // StoreInvoiceRequest's min:0) must not produce a zero-value income
    // ledger line (which would trip LedgerService's one-sided rule), but
    // stays on the invoice document; an all-$0 invoice (total 0) is rejected.
    // ---------------------------------------------------------------

    public function test_a_zero_value_line_is_not_posted_but_stays_on_the_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $chart = $this->chart($company);
        $freeIncome = $this->incomeAccount($company, ['code' => '4000']);
        $paidIncome = $this->incomeAccount($company, ['code' => '4010']);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $invoice = $this->draftInvoice($company, $contact);
        // A $0 free/promo line (unit_price 0) — allowed by validation.
        $freeLine = $this->invoiceLine($invoice, $freeIncome, ['quantity' => '1', 'unit_price' => 0, 'tax_rate_id' => null]);
        // A normal 100,000 @ 18% line.
        $this->invoiceLine($invoice, $paidIncome, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => $eighteenPercent->id]);

        $this->sendInvoice($invoice)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();
        // ONLY AR + the paid income line + tax — the $0 line produces NO line.
        $this->assertCount(3, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account_id', $freeIncome->id));

        $arLine = $entry->lines->firstWhere('account_id', $chart['ar']->id);
        $this->assertSame(118000, $arLine->debit);
        $this->assertSame($contact->id, $arLine->contact_id);

        $paidLine = $entry->lines->firstWhere('account_id', $paidIncome->id);
        $this->assertSame(100000, $paidLine->credit);

        $taxLine = $entry->lines->firstWhere('account_id', $chart['taxPayable']->id);
        $this->assertSame(18000, $taxLine->credit);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        // The $0 line still exists on the invoice document (frozen, not deleted).
        $invoice->refresh();
        $this->assertSame(2, $invoice->lines()->count());
        $freeLine->refresh();
        $this->assertSame(0, $freeLine->line_subtotal);
        $this->assertSame(100000, $invoice->subtotal);
        $this->assertSame(118000, $invoice->total);
    }

    public function test_an_all_zero_invoice_with_no_value_is_rejected_and_burns_no_number(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        // The ONLY line is $0 and untaxed — the invoice total is 0.
        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 0, 'tax_rate_id' => null]);

        $response = $this->sendInvoice($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertLedgerBalances();

        // No number burned: a fresh, non-zero invoice sent afterwards gets INV-0001.
        $real = $this->draftInvoice($company, $contact);
        $this->invoiceLine($real, $income, ['quantity' => '1', 'unit_price' => 5000]);
        $this->sendInvoice($real)->assertRedirect();
        $this->assertSame('INV-0001', $real->fresh()->number);
    }

    // ---------------------------------------------------------------
    // Document number: gap-free across sends
    // ---------------------------------------------------------------

    public function test_number_is_assigned_and_gap_free_across_two_sends(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $first = $this->draftInvoice($company, $contact);
        $this->invoiceLine($first, $income, ['quantity' => '1', 'unit_price' => 10000]);
        $second = $this->draftInvoice($company, $contact);
        $this->invoiceLine($second, $income, ['quantity' => '1', 'unit_price' => 20000]);

        $this->sendInvoice($first)->assertRedirect();
        $this->sendInvoice($second)->assertRedirect();

        $this->assertSame('INV-0001', $first->fresh()->number);
        $this->assertSame('INV-0002', $second->fresh()->number);
    }

    // ---------------------------------------------------------------
    // tax_rate_snapshot: frozen at send, unaffected by later rate changes
    // ---------------------------------------------------------------

    public function test_tax_rate_snapshot_is_frozen_and_unaffected_by_a_later_rate_change(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $tenPercent = $this->taxRate($company, ['rate' => '10.0000']);

        $invoice = $this->draftInvoice($company, $contact);
        $line = $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000, 'tax_rate_id' => $tenPercent->id]);

        $this->sendInvoice($invoice)->assertRedirect();

        $line->refresh();
        $this->assertSame('10.0000', $line->tax_rate_snapshot);
        $this->assertSame(10000, $line->line_tax);

        // Changing the tax rate's live percentage after the fact must not
        // retroactively alter the already-sent invoice's frozen snapshot.
        $tenPercent->update(['rate' => '20.0000']);

        $line->refresh();
        $this->assertSame('10.0000', $line->tax_rate_snapshot);
        $this->assertSame(10000, $invoice->fresh()->tax_total);
    }

    // ---------------------------------------------------------------
    // draft -> sent freeze
    // ---------------------------------------------------------------

    public function test_editing_a_line_after_send_throws(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $this->sendInvoice($invoice)->assertRedirect();

        $line = InvoiceLine::query()->sole();

        $this->expectException(\LogicException::class);
        $line->update(['description' => 'Changed after send']);
    }

    public function test_updating_the_invoice_itself_after_a_real_send_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $this->sendInvoice($invoice)->assertRedirect();

        $response = $this->patch("/invoices/{$invoice->id}", [
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [[
                'description' => 'Attempted edit',
                'quantity' => '1',
                'unit_price' => 99999,
                'income_account_id' => $income->id,
                'tax_rate_id' => null,
            ]],
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(10000, $invoice->fresh()->total);
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_send_posts_exactly_one_entry_and_burns_exactly_one_number(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $key = (string) Str::uuid();

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $first = $this->sendInvoice($invoice, $key);
        $second = $this->sendInvoice($invoice, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.sent')->count());
        $this->assertSame('INV-0001', $invoice->fresh()->number);
        $this->assertLedgerBalances();

        // The replay must not have burned a second number: a fresh invoice
        // sent afterwards gets INV-0002, not INV-0003.
        $another = $this->draftInvoice($company, $contact);
        $this->invoiceLine($another, $income, ['quantity' => '1', 'unit_price' => 5000]);
        $this->sendInvoice($another)->assertRedirect();
        $this->assertSame('INV-0002', $another->fresh()->number);
    }

    // ---------------------------------------------------------------
    // Guards: not-draft, no lines, ineligible contact
    // ---------------------------------------------------------------

    public function test_sending_a_non_draft_invoice_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);

        $invoice = $this->draftInvoice($company, $contact, ['status' => InvoiceStatus::Sent->value, 'number' => 'INV-9001']);

        $response = $this->sendInvoice($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_a_draft_with_no_lines_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);

        $invoice = $this->draftInvoice($company, $contact);

        $response = $this->sendInvoice($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_sending_an_invoice_whose_contact_is_vendor_only_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        // A vendor-only contact should never reach a real invoice through
        // StoreInvoiceRequest's own guard — this proves InvoiceService's
        // defense-in-depth guard for a row that reaches send() some other way
        // (e.g. the contact's type changed after the draft was created).
        $vendor = $this->customer($company, ['type' => ContactType::Vendor->value]);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $vendor);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $response = $this->sendInvoice($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertNull($invoice->fresh()->number);
    }

    // ---------------------------------------------------------------
    // Gate: invoices.send — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_send_an_invoice(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->customer($company);
            $this->chart($company);
            $income = $this->incomeAccount($company);

            $invoice = $this->draftInvoice($company, $contact);
            $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

            $this->sendInvoice($invoice)->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_sending_an_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $response = $this->sendInvoice($invoice);

        $response->assertForbidden();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
        $this->assertNull($invoice->fresh()->number);
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_sending_into_a_locked_period_returns_422_and_posts_nothing_and_burns_no_number(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact, ['issue_date' => '2026-06-15']);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $response = $this->sendInvoice($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertLedgerBalances();

        // No number was burned by the failed attempt: a fresh, unlocked
        // invoice sent afterwards still gets INV-0001.
        $unlocked = $this->draftInvoice($company, $contact, ['issue_date' => '2026-07-01']);
        $this->invoiceLine($unlocked, $income, ['quantity' => '1', 'unit_price' => 5000]);
        $this->sendInvoice($unlocked)->assertRedirect();
        $this->assertSame('INV-0001', $unlocked->fresh()->number);
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_invoice_404s_on_send(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $foreignInvoice = $this->draftInvoice($companyA, $contactA);
        $this->invoiceLine($foreignInvoice, $incomeA, ['quantity' => '1', 'unit_price' => 10000]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->sendInvoice($foreignInvoice)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Idempotency key required
    // ---------------------------------------------------------------

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);

        $response = $this->postJson("/invoices/{$invoice->id}/send");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }
}
