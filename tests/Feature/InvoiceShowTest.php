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
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 3 Task 11: the Invoices/Show detail page's data contract — the
 * `invoice`/`bankAccounts` Inertia props InvoiceController::show renders,
 * plus the shared `can` map entries (invoices.send/payments.record/
 * invoices.void) Show.tsx's action bar gates on. Every fixture here is
 * hand-rolled directly on the Invoice/InvoiceLine/InvoicePayment rows
 * (mirroring InvoicePaymentTest's sentInvoice() helper) since this only
 * tests what the CONTROLLER SERIALIZES — the actual send/payment/void
 * posting logic is InvoiceSendTest/InvoicePaymentTest/InvoiceVoidTest's
 * concern.
 */
class InvoiceShowTest extends TestCase
{
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

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /**
     * A draft invoice with one line — via CompanyContext::runFor since
     * InvoiceLine::booted()'s freeze guard needs a live company context
     * (mirrors InvoiceSendTest's invoiceLine() helper).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function draftInvoiceWithLine(Company $company, Contact $contact, Account $income, array $overrides = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
        ], $overrides));

        app(CompanyContext::class)->runFor($company, fn () => InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $company->id,
            'income_account_id' => $income->id,
            'description' => 'Consulting hour',
            'quantity' => '1',
            'unit_price' => 50000,
            'line_subtotal' => 50000,
            'line_tax' => 0,
        ]));

        return $invoice;
    }

    /**
     * Flips an already-lined draft straight to a posted status by directly
     * mutating the Invoice row (never through InvoiceLine, so the freeze
     * guard never fires) — mirrors InvoicePaymentTest/InvoiceVoidTest's
     * hand-rolled "already sent" fixtures.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function markPosted(Invoice $invoice, InvoiceStatus $status, array $overrides = []): Invoice
    {
        $invoice->forceFill(array_merge([
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'status' => $status->value,
            'issue_date' => '2026-07-01',
            'sent_at' => now(),
        ], $overrides))->save();

        return $invoice->fresh();
    }

    public function test_show_renders_a_draft_invoice_with_its_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company, ['name' => 'Acme Co']);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoiceWithLine($company, $contact, $income);

        $response = $this->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($invoice) {
            $page->component('Invoices/Show');
            $page->where('invoice.id', $invoice->id);
            $page->where('invoice.number', null);
            $page->where('invoice.status', 'draft');
            $page->where('invoice.contact.name', 'Acme Co');
            $page->where('invoice.is_opening', false);
            $page->has('invoice.lines', 1);
            $page->where('invoice.lines.0.description', 'Consulting hour');
            $page->where('invoice.lines.0.line_subtotal', 50000);
            $page->has('invoice.payments', 0);
            $page->has('bankAccounts');
        });
    }

    /**
     * Phase 7 Task 2 (archiving, surfaced everywhere): archiving a contact
     * hides it from NEW-document pickers (InvoiceController::formOptions),
     * but an EXISTING invoice that already references it must keep showing
     * it, unchanged — archiving is not deletion, and Contact's global scope
     * has no is_archived filter for exactly this reason.
     */
    public function test_show_still_displays_an_archived_contacts_name_on_an_existing_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company, ['name' => 'Acme Co']);
        $income = $this->incomeAccount($company);
        $invoice = $this->draftInvoiceWithLine($company, $contact, $income);

        $this->post("/contacts/{$contact->id}/archive")->assertRedirect(route('contacts.index'));
        $this->assertTrue($contact->fresh()->is_archived);

        $response = $this->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($invoice) {
            $page->component('Invoices/Show');
            $page->where('invoice.id', $invoice->id);
            $page->where('invoice.contact.name', 'Acme Co');
        });
    }

    public function test_show_shares_the_narrower_action_abilities_for_an_owner(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->draftInvoiceWithLine($company, $contact, $income);

        // `can` is a FLAT map whose own keys contain dots (e.g. "invoices.send")
        // — a dot-path assertion like where('can.invoices.send', ...) would
        // misparse that as three nested levels, so this reads the whole map
        // and indexes it directly instead.
        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['invoices.send'] === true
                && $can['payments.record'] === true
                && $can['invoices.void'] === true)
        );
    }

    public function test_show_hides_the_narrower_action_abilities_for_staff(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->draftInvoiceWithLine($company, $contact, $income);

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can', fn ($can) => $can['invoices.send'] === false
                && $can['payments.record'] === false
                && $can['invoices.void'] === false)
        );
    }

    public function test_show_includes_a_posted_invoices_payments_with_the_bank_account_name(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $bank = $this->bankAccount($company, ['name' => 'Meezan Current']);

        $invoice = $this->draftInvoiceWithLine($company, $contact, $income);
        $invoice = $this->markPosted($invoice, InvoiceStatus::PartiallyPaid, [
            'subtotal' => 50000, 'tax_total' => 0, 'total' => 50000,
            'amount_paid' => 20000, 'balance' => 30000,
            'base_subtotal' => 50000, 'base_tax_total' => 0, 'base_total' => 50000,
        ]);

        InvoicePayment::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'bank_account_id' => $bank->id,
            'amount' => 20000,
            'base_amount' => 20000,
            'base_ar_relief' => 20000,
            'fx_rate' => null,
            'status' => 'posted',
        ]);

        $response = $this->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('Invoices/Show');
            $page->where('invoice.status', 'partially_paid');
            $page->where('invoice.balance', 30000);
            $page->has('invoice.payments', 1);
            $page->where('invoice.payments.0.amount', 20000);
            $page->where('invoice.payments.0.status', 'posted');
            $page->where('invoice.payments.0.bank_account.name', 'Meezan Current');
        });
    }

    public function test_show_includes_base_currency_amounts_and_rate_for_a_foreign_invoice(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoiceWithLine($company, $contact, $income, ['currency_code' => 'USD']);
        $invoice = $this->markPosted($invoice, InvoiceStatus::Sent, [
            'fx_rate' => '280.00000000',
            'subtotal' => 50000, 'tax_total' => 0, 'total' => 50000,
            'base_subtotal' => 14000000, 'base_tax_total' => 0, 'base_total' => 14000000,
        ]);

        $response = $this->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.currency_code', 'USD')
            ->where('invoice.fx_rate', '280.00000000')
            ->where('invoice.total', 50000)
            ->where('invoice.base_total', 14000000)
        );
    }

    public function test_show_flags_an_is_opening_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'OB-0001',
            'is_opening' => true,
            'total' => 20000,
            'balance' => 20000,
            'base_total' => 20000,
        ]);

        $this->get("/invoices/{$invoice->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invoice.is_opening', true)
            ->where('invoice.number', 'OB-0001')
        );
    }

    public function test_show_404s_for_a_different_companys_invoice(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $contactB = Contact::factory()->create(['company_id' => $companyB->id, 'type' => ContactType::Customer->value]);

        $foreignInvoice = Invoice::factory()->create([
            'company_id' => $companyB->id,
            'contact_id' => $contactB->id,
        ]);

        $this->get("/invoices/{$foreignInvoice->id}")->assertNotFound();
    }
}
