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
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InvoiceDraftTest extends TestCase
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

    private function customer(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ], $overrides));
    }

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Consulting hour',
            'quantity' => '1',
            'unit_price' => 10000,
            'tax_rate_id' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $data */
    private function postInvoice(array $data): TestResponse
    {
        return $this->post('/invoices', $data);
    }

    /** @param array<string, mixed> $data */
    private function patchInvoice(Invoice $invoice, array $data): TestResponse
    {
        return $this->patch("/invoices/{$invoice->id}", $data);
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_owner_can_create_a_draft_with_a_single_untaxed_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'notes' => 'Thanks for your business',
            'lines' => [
                $this->line(['income_account_id' => $income->id, 'unit_price' => 150000]),
            ],
        ]);

        $invoice = Invoice::query()->sole();
        $response->assertRedirect(route('invoices.edit', $invoice->id));

        $this->assertSame($contact->id, $invoice->contact_id);
        $this->assertNull($invoice->number);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('PKR', $invoice->currency_code);
        $this->assertSame('1.00000000', $invoice->fx_rate);
        $this->assertSame(150000, $invoice->subtotal);
        $this->assertSame(0, $invoice->tax_total);
        $this->assertSame(150000, $invoice->total);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(150000, $invoice->balance);
        $this->assertSame(150000, $invoice->base_subtotal);
        $this->assertSame(0, $invoice->base_tax_total);
        $this->assertSame(150000, $invoice->base_total);
        $this->assertFalse($invoice->is_opening);

        $line = InvoiceLine::query()->sole();
        $this->assertSame('Consulting hour', $line->description);
        $this->assertSame(150000, $line->unit_price);
        $this->assertSame(150000, $line->line_subtotal);
        $this->assertSame(0, $line->line_tax);
        $this->assertNull($line->tax_rate_snapshot);

        $audit = ActivityLog::query()->where('action', 'invoice.draft_created')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame(150000, $audit->after['total']);
    }

    /** The brief's own odd-paisa worked example, multi-line, mixed taxed/untaxed, quantity 2.5. */
    public function test_totals_are_computed_exactly_for_a_multi_line_mixed_taxed_and_untaxed_draft(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [
                // 3 x 3333 = 9999 subtotal; 18% of 9999 = 1799.82 -> half-up 1800 tax.
                $this->line(['quantity' => '3', 'unit_price' => 3333, 'income_account_id' => $income->id, 'tax_rate_id' => $eighteenPercent->id]),
                // 2.5 x 10000 = 25000 subtotal; untaxed.
                $this->line(['quantity' => '2.5', 'unit_price' => 10000, 'income_account_id' => $income->id]),
            ],
        ]);

        $invoice = Invoice::query()->sole();
        $response->assertRedirect(route('invoices.edit', $invoice->id));

        $this->assertSame(34999, $invoice->subtotal);
        $this->assertSame(1800, $invoice->tax_total);
        $this->assertSame(36799, $invoice->total);
        $this->assertSame(36799, $invoice->balance);

        $lines = InvoiceLine::query()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(9999, $lines[0]->line_subtotal);
        $this->assertSame(1800, $lines[0]->line_tax);
        $this->assertSame(25000, $lines[1]->line_subtotal);
        $this->assertSame(0, $lines[1]->line_tax);
    }

    // ---------------------------------------------------------------
    // Validation: contact must be a customer/both contact
    // ---------------------------------------------------------------

    public function test_a_vendor_only_contact_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->customer($company, ['type' => ContactType::Vendor->value]);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $vendor->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_both_type_contact_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $both = $this->customer($company, ['type' => ContactType::Both->value]);
        $income = $this->incomeAccount($company);

        $this->postInvoice([
            'contact_id' => $both->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ])->assertRedirect();

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_an_archived_contact_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company, ['is_archived' => true]);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_foreign_contact_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $foreignContact = $this->customer($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $income = $this->incomeAccount($companyB);

        $response = $this->postInvoice([
            'contact_id' => $foreignContact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: income account must be income-type, non-archived, tenant
    // ---------------------------------------------------------------

    public function test_a_non_income_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $expenseAccount = Account::factory()->create([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $expenseAccount->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.income_account_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_an_archived_income_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company, ['is_archived' => true]);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.income_account_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: tax rate must be tenant, non-archived
    // ---------------------------------------------------------------

    public function test_an_archived_tax_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);
        $archivedRate = $this->taxRate($company, ['is_archived' => true]);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id, 'tax_rate_id' => $archivedRate->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.tax_rate_id');
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: quantity, currency, dates
    // ---------------------------------------------------------------

    public function test_zero_quantity_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id, 'quantity' => '0'])],
        ]);

        $response->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_quantity_with_five_decimal_places_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id, 'quantity' => '2.50001'])],
        ]);

        $response->assertSessionHasErrors('lines.0.quantity');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_an_unrecognized_currency_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'XYZ',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('currency_code');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_due_date_before_issue_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'issue_date' => '2026-07-15',
            'due_date' => '2026-07-01',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_at_least_one_line_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);

        $response = $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Only status=draft is editable/deletable
    // ---------------------------------------------------------------

    public function test_a_draft_can_be_updated_and_lines_are_replaced_wholesale(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id, 'unit_price' => 10000])],
        ]);
        $invoice = Invoice::query()->sole();
        $originalLineId = InvoiceLine::query()->sole()->id;

        $newContact = $this->customer($company);
        $response = $this->patchInvoice($invoice, [
            'contact_id' => $newContact->id,
            'currency_code' => 'USD',
            'notes' => 'Updated notes',
            'lines' => [
                $this->line(['income_account_id' => $income->id, 'unit_price' => 20000, 'description' => 'Revised line']),
            ],
        ]);

        $response->assertRedirect(route('invoices.edit', $invoice->id));

        $invoice->refresh();
        $this->assertSame($newContact->id, $invoice->contact_id);
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertSame('Updated notes', $invoice->notes);
        $this->assertSame(20000, $invoice->total);
        $this->assertSame(20000, $invoice->balance);

        $lines = InvoiceLine::query()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('Revised line', $lines->first()->description);
        $this->assertNotSame($originalLineId, $lines->first()->id);

        $audit = ActivityLog::query()->where('action', 'invoice.draft_updated')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
        $this->assertSame(20000, $audit->after['total']);
    }

    public function test_updating_a_posted_invoice_is_rejected_and_nothing_changes(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-9001',
            'total' => 50000,
        ]);

        $response = $this->patchInvoice($invoice, [
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(50000, $invoice->fresh()->total);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $income = $this->incomeAccount($company);

        $this->postInvoice([
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ]);
        $invoice = Invoice::query()->sole();

        $response = $this->delete("/invoices/{$invoice->id}");

        $response->assertRedirect(route('invoices.index'));
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceLine::query()->count());

        $audit = ActivityLog::query()->where('action', 'invoice.draft_deleted')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
    }

    public function test_deleting_a_posted_invoice_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);

        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-9002',
        ]);

        $response = $this->delete("/invoices/{$invoice->id}");

        $response->assertSessionHasErrors('status');
        $this->assertSame(1, Invoice::query()->count());
    }

    // ---------------------------------------------------------------
    // Gate: invoices.draft — Owner/Admin/Accountant/Staff all can draft
    // ---------------------------------------------------------------

    public function test_all_four_roles_can_create_a_draft(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant, Role::Staff] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $contact = $this->customer($company);
            $income = $this->incomeAccount($company);

            $this->postInvoice([
                'contact_id' => $contact->id,
                'currency_code' => 'PKR',
                'lines' => [$this->line(['income_account_id' => $income->id])],
            ])->assertRedirect();
        }
    }

    // Gate denial for a true non-member (no company membership at all) is
    // already proven at the Gate layer by
    // PermissionMatrixTest::test_non_member_is_denied_everything — HTTP-level
    // 403 can't be exercised the same way here because RequireCompany
    // redirects a company-less user to onboarding before the can:
    // middleware even runs (same reasoning PermissionMatrixTest itself uses
    // Gate::forUser() directly rather than a real request for that case).

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_invoice_404s_on_edit_update_and_destroy(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $foreignInvoice = Invoice::factory()->create([
            'company_id' => $companyA->id,
            'contact_id' => $contactA->id,
        ]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $income = $this->incomeAccount($companyB);
        $contactB = $this->customer($companyB);

        $this->get("/invoices/{$foreignInvoice->id}/edit")->assertNotFound();
        $this->patch("/invoices/{$foreignInvoice->id}", [
            'contact_id' => $contactB->id,
            'currency_code' => 'PKR',
            'lines' => [$this->line(['income_account_id' => $income->id])],
        ])->assertNotFound();
        $this->delete("/invoices/{$foreignInvoice->id}")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // index / create form options
    // ---------------------------------------------------------------

    public function test_index_lists_drafts_and_posted_invoices(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);

        $draft = Invoice::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Draft->value]);
        $sent = Invoice::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Sent->value, 'number' => 'INV-1234']);

        $response = $this->get('/invoices');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($draft, $sent) {
            $page->component('Invoices/Index');
            $page->where('invoices', function ($invoices) use ($draft, $sent) {
                $ids = collect($invoices)->pluck('id')->all();

                return in_array($draft->id, $ids, true) && in_array($sent->id, $ids, true);
            });
        });
    }

    public function test_create_form_only_lists_non_archived_customer_contacts_and_income_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $activeCustomer = $this->customer($company, ['name' => 'Active Customer']);
        $archivedCustomer = $this->customer($company, ['name' => 'Archived Customer', 'is_archived' => true]);
        $vendorOnly = $this->customer($company, ['name' => 'Vendor Only', 'type' => ContactType::Vendor->value]);
        $activeIncome = $this->incomeAccount($company);
        $archivedIncome = $this->incomeAccount($company, ['is_archived' => true]);

        $response = $this->get('/invoices/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($activeCustomer, $archivedCustomer, $vendorOnly, $activeIncome, $archivedIncome) {
            $page->component('Invoices/Edit');
            $page->where('invoice', null);
            $page->where('contacts', function ($contacts) use ($activeCustomer, $archivedCustomer, $vendorOnly) {
                $ids = collect($contacts)->pluck('id')->all();

                return in_array($activeCustomer->id, $ids, true)
                    && ! in_array($archivedCustomer->id, $ids, true)
                    && ! in_array($vendorOnly->id, $ids, true);
            });
            $page->where('incomeAccounts', function ($accounts) use ($activeIncome, $archivedIncome) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($activeIncome->id, $ids, true) && ! in_array($archivedIncome->id, $ids, true);
            });
        });
    }
}
