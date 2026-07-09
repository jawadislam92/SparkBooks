<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\InvoicePdf;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 Task 9 — the invoice PDF. dompdf's own output is binary, so most
 * assertions here render resources/views/pdf/invoice.blade.php directly
 * (mirrors the task brief's own testing instructions) and assert on the
 * HTML; a single smoke test drives the real HTTP route end to end and checks
 * the response actually is a PDF (%PDF header + application/pdf).
 */
class InvoicePdfTest extends TestCase
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

    /**
     * A soon-to-be-"sent" invoice's row, built directly via the factory
     * (never through InvoiceService::send — that would drag in the whole
     * ledger-posting flow, which this PDF task doesn't touch). Deliberately
     * created as status=draft even though every field otherwise matches a
     * sent invoice: InvoiceLine::booted() freezes a posted invoice's lines,
     * so the lines must be added via line() below WHILE this is still draft
     * — promoteToSent() then flips the status last. Defaults mirror a
     * simple domestic PKR invoice: subtotal 1,000.00 + tax 180.00 = total
     * 1,180.00, the number this test suite asserts on throughout.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sentInvoice(Company $company, Contact $contact, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'number' => 'INV-0001',
            'currency_code' => 'PKR',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'fx_rate' => '1.00000000',
            'subtotal' => 100000,
            'tax_total' => 18000,
            'total' => 118000,
            'amount_paid' => 0,
            'balance' => 118000,
            'base_subtotal' => 100000,
            'base_tax_total' => 18000,
            'base_total' => 118000,
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor — InvoiceLine::booted()'s freeze
     * guard runs Invoice::query()->find() (a real, company-scoped SELECT) on
     * every create, so it needs a live CompanyContext even when this helper
     * runs before any HTTP request has set one via SetCompanyContext.
     * Mirrors InvoiceFxSendTest/InvoicePaymentTest's identical helper.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function line(Invoice $invoice, Account $incomeAccount, array $overrides = []): InvoiceLine
    {
        $company = Company::query()->findOrFail($invoice->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => InvoiceLine::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'income_account_id' => $incomeAccount->id,
            'description' => 'Consulting services',
            'quantity' => '1',
            'unit_price' => 100000,
            'line_subtotal' => 100000,
            'line_tax' => 18000,
        ], $overrides)));
    }

    /**
     * Flips a draft invoice to Sent AFTER its lines have been added via
     * line() above — must run last, for the same freeze-guard reason
     * documented on sentInvoice()/line(). Invoice itself carries no such
     * guard (only InvoiceLine does — see InvoiceLine::booted()'s docblock),
     * so a plain forceFill+save is safe here.
     */
    private function promoteToSent(Invoice $invoice): Invoice
    {
        $invoice->forceFill(['status' => InvoiceStatus::Sent->value])->save();

        return $invoice->fresh();
    }

    private function renderView(Invoice $invoice): string
    {
        return view('pdf.invoice', (new InvoicePdf)->viewData($invoice))->render();
    }

    // ---------------------------------------------------------------
    // Route smoke test: a real PDF comes back over HTTP.
    // ---------------------------------------------------------------

    public function test_pdf_route_returns_a_real_pdf_for_a_sent_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->sentInvoice($company, $contact);
        $this->line($invoice, $income);
        $invoice = $this->promoteToSent($invoice);

        $response = $this->get("/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ---------------------------------------------------------------
    // View render assertions — dompdf's own binary output isn't assertable,
    // so these render the Blade view directly, per the task brief.
    // ---------------------------------------------------------------

    public function test_view_contains_the_number_contact_name_and_formatted_total(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $contact = $this->customer($company, ['name' => 'Acme Trading Co']);
        $income = $this->incomeAccount($company);
        $invoice = $this->sentInvoice($company, $contact);
        $this->line($invoice, $income);
        $invoice = $this->promoteToSent($invoice);

        $html = $this->renderView($invoice);

        $this->assertStringContainsString('INV-0001', $html);
        $this->assertStringContainsString('Acme Trading Co', $html);
        $this->assertStringContainsString('1,180.00', $html);
    }

    public function test_draft_invoice_view_shows_draft_with_no_number(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Draft->value,
            'number' => null,
            'currency_code' => 'PKR',
            'subtotal' => 5000,
            'tax_total' => 0,
            'total' => 5000,
            'base_subtotal' => 5000,
            'base_total' => 5000,
        ]);
        $this->line($invoice, $income, [
            'unit_price' => 5000,
            'line_subtotal' => 5000,
            'line_tax' => 0,
        ]);

        $html = $this->renderView($invoice);

        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringNotContainsString('INV-', $html);
    }

    public function test_fx_invoice_view_shows_dual_currency_amounts_and_the_rate(): void
    {
        $company = Company::factory()->create(); // base_currency PKR (factory default)
        app(CompanyContext::class)->set($company);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        // The spec §17.1 worked example, exactly (InvoiceFxSendTest's own
        // numbers): USD 1,000.00 + 18% tax @ fx_rate 280 -> PKR 330,400.00.
        $invoice = $this->sentInvoice($company, $contact, [
            'currency_code' => 'USD',
            'fx_rate' => '280.00000000',
            'subtotal' => 100000,
            'tax_total' => 18000,
            'total' => 118000,
            'base_subtotal' => 28000000,
            'base_tax_total' => 5040000,
            'base_total' => 33040000,
        ]);
        $this->line($invoice, $income);
        $invoice = $this->promoteToSent($invoice);

        $html = $this->renderView($invoice);

        $this->assertStringContainsString('USD', $html);
        $this->assertStringContainsString('1,180.00', $html); // document-currency total, primary
        $this->assertStringContainsString('≈', $html);
        $this->assertStringContainsString('330,400.00', $html); // base-currency total
        $this->assertStringContainsString('280.00', $html); // fx_rate, 2dp
        $this->assertStringContainsString('PKR', $html); // base currency code on the ≈ line
    }

    public function test_domestic_invoice_view_shows_only_one_currency_and_no_approx_line(): void
    {
        $company = Company::factory()->create(); // base_currency PKR
        app(CompanyContext::class)->set($company);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->sentInvoice($company, $contact); // currency_code PKR === base
        $this->line($invoice, $income);
        $invoice = $this->promoteToSent($invoice);

        $html = $this->renderView($invoice);

        $this->assertStringNotContainsString('≈', $html);
    }

    // ---------------------------------------------------------------
    // Money-format helper: integer math only, matches the exact examples
    // the task brief specifies.
    // ---------------------------------------------------------------

    public function test_money_formatter_handles_an_odd_paisa_total(): void
    {
        $this->assertStringContainsString('1,500.50', InvoicePdf::formatMoney(150050, 'PKR'));
    }

    public function test_money_formatter_handles_a_large_amount_with_thousands_separators(): void
    {
        $this->assertStringContainsString('1,000,000.00', InvoicePdf::formatMoney(100000000, 'PKR'));
    }

    public function test_money_formatter_handles_zero(): void
    {
        $this->assertStringContainsString('0.00', InvoicePdf::formatMoney(0, 'PKR'));
    }

    public function test_money_formatter_prefixes_the_currency_code(): void
    {
        $this->assertSame('USD 1,180.00', InvoicePdf::formatMoney(118000, 'USD'));
    }

    public function test_rate_formatter_rounds_half_up_to_two_decimal_places(): void
    {
        $this->assertSame('280.00', InvoicePdf::formatRate('280.00000000'));
        $this->assertSame('280.12', InvoicePdf::formatRate('280.12345678')); // truncates below the half-up threshold
        $this->assertSame('280.13', InvoicePdf::formatRate('280.125'));       // exact tie rounds up
    }

    /**
     * Mirrors the project's own CI gate (.github/workflows/ci.yml, "No float
     * on money paths") by hand for this specific file: the money/rate
     * formatters must never touch (float)/(double)/floatval — every split is
     * intdiv/%/bcmath.
     */
    public function test_money_helper_source_contains_no_float_or_double_cast(): void
    {
        $source = file_get_contents(app_path('Services/InvoicePdf.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('(double)', $source);
        $this->assertStringNotContainsString('floatval', $source);
    }

    // ---------------------------------------------------------------
    // Gate / scoped
    // ---------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->sentInvoice($company, $contact);
        $this->line($invoice, $income);
        $invoice = $this->promoteToSent($invoice);
        app(CompanyContext::class)->clear();

        $response = $this->get("/invoices/{$invoice->id}/pdf");

        $response->assertRedirect(route('login'));
    }

    /**
     * All four roles carry invoices.draft (config/permissions.php §7.14), so
     * there's no role-based 403 case to exercise here — this instead spot-
     * checks that the gate lets every one of them through, including Staff
     * (the narrowest role, which can draft but not send/void an invoice).
     */
    public function test_every_role_with_invoices_draft_can_view_the_pdf(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant, Role::Staff] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->customer($company);
            $income = $this->incomeAccount($company);
            $invoice = $this->sentInvoice($company, $contact);
            $this->line($invoice, $income);
            $invoice = $this->promoteToSent($invoice);

            $this->get("/invoices/{$invoice->id}/pdf")->assertOk();
        }
    }

    public function test_a_foreign_company_invoice_404s_on_the_pdf_route(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $foreignInvoice = $this->sentInvoice($companyA, $contactA);
        $this->line($foreignInvoice, $incomeA);
        $foreignInvoice = $this->promoteToSent($foreignInvoice);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->get("/invoices/{$foreignInvoice->id}/pdf")->assertNotFound();
    }
}
