<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function invoice(Company $company, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => Contact::factory()->create(['company_id' => $company->id])->id,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // InvoiceStatus enum: cast + helpers
    // ---------------------------------------------------------------

    public function test_status_casts_to_the_enum_and_helpers_are_correct(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $draft = $this->invoice($company, ['status' => InvoiceStatus::Draft->value]);
        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertTrue($draft->status->canEdit());
        $this->assertFalse($draft->status->isPosted());
        $this->assertFalse($draft->status->canReceivePayment());
        $this->assertFalse($draft->status->canVoid());

        $sent = $this->invoice($company, ['status' => InvoiceStatus::Sent->value, 'number' => 'INV-1001']);
        $this->assertTrue($sent->status->isPosted());
        $this->assertFalse($sent->status->canEdit());
        $this->assertTrue($sent->status->canReceivePayment());
        $this->assertTrue($sent->status->canVoid());

        $partial = $this->invoice($company, ['status' => InvoiceStatus::PartiallyPaid->value, 'number' => 'INV-1002']);
        $this->assertTrue($partial->status->isPosted());
        $this->assertFalse($partial->status->canEdit());
        $this->assertTrue($partial->status->canReceivePayment());
        $this->assertTrue($partial->status->canVoid());

        $paid = $this->invoice($company, ['status' => InvoiceStatus::Paid->value, 'number' => 'INV-1003']);
        $this->assertTrue($paid->status->isPosted());
        $this->assertFalse($paid->status->canEdit());
        $this->assertFalse($paid->status->canReceivePayment());
        $this->assertTrue($paid->status->canVoid());

        $void = $this->invoice($company, ['status' => InvoiceStatus::Void->value, 'number' => 'INV-1004']);
        $this->assertFalse($void->status->isPosted());
        $this->assertFalse($void->status->canEdit());
        $this->assertFalse($void->status->canReceivePayment());
        $this->assertFalse($void->status->canVoid());
    }

    // ---------------------------------------------------------------
    // is_opening default
    // ---------------------------------------------------------------

    public function test_is_opening_defaults_false_via_the_model(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $invoice = $this->invoice($company);

        $this->assertFalse($invoice->is_opening);
    }

    public function test_is_opening_column_defaults_false_at_the_database_level(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        // Raw insert omitting is_opening entirely — proves the MIGRATION
        // default, not just the factory's explicit value above.
        DB::table('invoices')->insert([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('invoices')->where('company_id', $company->id)->first();
        $this->assertSame(0, (int) $row->is_opening);
    }

    // ---------------------------------------------------------------
    // unique(company_id, number) — MySQL allows multiple NULLs
    // ---------------------------------------------------------------

    public function test_multiple_draft_invoices_with_null_number_do_not_collide(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $this->invoice($company, ['number' => null]);
        $this->invoice($company, ['number' => null]);

        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_duplicate_number_for_the_same_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $row = [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'number' => 'INV-0001',
            'status' => InvoiceStatus::Sent->value,
            'currency_code' => 'PKR',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('invoices')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('invoices')->insert($row);
    }

    public function test_same_number_is_allowed_across_different_companies(): void
    {
        $companyA = Company::factory()->create();
        $contactA = Contact::factory()->create(['company_id' => $companyA->id]);
        $companyB = Company::factory()->create();
        $contactB = Contact::factory()->create(['company_id' => $companyB->id]);

        $base = [
            'number' => 'INV-0001',
            'status' => InvoiceStatus::Sent->value,
            'currency_code' => 'PKR',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('invoices')->insert(array_merge($base, ['company_id' => $companyA->id, 'contact_id' => $contactA->id]));
        DB::table('invoices')->insert(array_merge($base, ['company_id' => $companyB->id, 'contact_id' => $contactB->id]));

        $this->assertSame(1, DB::table('invoices')->where('company_id', $companyA->id)->where('number', 'INV-0001')->count());
        $this->assertSame(1, DB::table('invoices')->where('company_id', $companyB->id)->where('number', 'INV-0001')->count());
    }

    // ---------------------------------------------------------------
    // Composite tenant FK on contact_id
    // ---------------------------------------------------------------

    public function test_composite_fk_rejects_a_contact_from_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignContact = Contact::factory()->create(['company_id' => $companyB->id]);

        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'company_id' => $companyA->id,
            'contact_id' => $foreignContact->id,
            'currency_code' => 'PKR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_composite_fk_accepts_a_same_company_contact(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        DB::table('invoices')->insert([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'currency_code' => 'PKR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('invoices')->where('contact_id', $contact->id)->count());
    }

    // ---------------------------------------------------------------
    // InvoiceLine draft-mutability freeze
    // ---------------------------------------------------------------

    public function test_a_line_on_a_draft_invoice_can_be_created_and_edited(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $invoice = $this->invoice($company, ['status' => InvoiceStatus::Draft->value]);

        $line = InvoiceLine::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);

        $line->update(['description' => 'Updated description']);

        $this->assertSame('Updated description', $line->fresh()->description);
    }

    public function test_a_line_on_a_draft_invoice_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $invoice = $this->invoice($company, ['status' => InvoiceStatus::Draft->value]);

        $line = InvoiceLine::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);

        $line->delete();

        $this->assertSame(0, InvoiceLine::query()->where('id', $line->id)->count());
    }

    public function test_adding_a_line_to_a_posted_invoice_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $invoice = $this->invoice($company, ['status' => InvoiceStatus::Sent->value, 'number' => 'INV-2001']);

        $this->expectException(LogicException::class);

        InvoiceLine::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_editing_a_line_on_a_posted_invoice_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $invoice = $this->invoice($company, ['status' => InvoiceStatus::Draft->value]);

        $line = InvoiceLine::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);

        // Set the invoice to 'sent' directly (no send service exists yet —
        // this task is schema-only) and confirm the line is now frozen.
        $invoice->update(['status' => InvoiceStatus::Sent->value, 'number' => 'INV-2002']);

        $this->expectException(LogicException::class);
        $line->update(['description' => 'Should not be allowed']);
    }

    public function test_deleting_a_line_on_a_posted_invoice_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $invoice = $this->invoice($company, ['status' => InvoiceStatus::Draft->value]);

        $line = InvoiceLine::factory()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
        ]);

        $invoice->update(['status' => InvoiceStatus::Sent->value, 'number' => 'INV-2003']);

        $this->expectException(LogicException::class);
        $line->delete();
    }
}
