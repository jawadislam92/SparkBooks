<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Contact;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class BillModelTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function bill(Company $company, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => Contact::factory()->create(['company_id' => $company->id])->id,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // BillStatus enum: cast + helpers
    // ---------------------------------------------------------------

    public function test_status_casts_to_the_enum_and_helpers_are_correct(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $draft = $this->bill($company, ['status' => BillStatus::Draft->value]);
        $this->assertSame(BillStatus::Draft, $draft->status);
        $this->assertTrue($draft->status->canEdit());
        $this->assertTrue($draft->status->canReceive());
        $this->assertFalse($draft->status->isPosted());
        $this->assertFalse($draft->status->canReceivePayment());
        $this->assertFalse($draft->status->canVoid());

        $received = $this->bill($company, ['status' => BillStatus::Received->value]);
        $this->assertTrue($received->status->isPosted());
        $this->assertFalse($received->status->canEdit());
        $this->assertFalse($received->status->canReceive());
        $this->assertTrue($received->status->canReceivePayment());
        $this->assertTrue($received->status->canVoid());

        $partial = $this->bill($company, ['status' => BillStatus::PartiallyPaid->value]);
        $this->assertTrue($partial->status->isPosted());
        $this->assertFalse($partial->status->canEdit());
        $this->assertFalse($partial->status->canReceive());
        $this->assertTrue($partial->status->canReceivePayment());
        $this->assertTrue($partial->status->canVoid());

        $paid = $this->bill($company, ['status' => BillStatus::Paid->value]);
        $this->assertTrue($paid->status->isPosted());
        $this->assertFalse($paid->status->canEdit());
        $this->assertFalse($paid->status->canReceive());
        $this->assertFalse($paid->status->canReceivePayment());
        $this->assertTrue($paid->status->canVoid());

        $void = $this->bill($company, ['status' => BillStatus::Void->value]);
        $this->assertFalse($void->status->isPosted());
        $this->assertFalse($void->status->canEdit());
        $this->assertFalse($void->status->canReceive());
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

        $bill = $this->bill($company);

        $this->assertFalse($bill->is_opening);
    }

    // ---------------------------------------------------------------
    // contact_id: NOT NULL (bills always have a vendor) + composite tenant FK
    // ---------------------------------------------------------------

    public function test_contact_id_not_null_is_enforced_at_the_database_level(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('bills')->insert([
            'company_id' => $company->id,
            'contact_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_composite_fk_rejects_a_contact_from_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignContact = Contact::factory()->create(['company_id' => $companyB->id]);

        $this->expectException(QueryException::class);

        DB::table('bills')->insert([
            'company_id' => $companyA->id,
            'contact_id' => $foreignContact->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_composite_fk_accepts_a_same_company_contact(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        DB::table('bills')->insert([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('bills')->where('contact_id', $contact->id)->count());
    }

    // ---------------------------------------------------------------
    // No internal numbering — bills carry the vendor's own `reference`
    // ---------------------------------------------------------------

    public function test_multiple_bills_with_the_same_reference_are_allowed(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $this->bill($company, ['reference' => 'VENDOR-INV-1']);
        $this->bill($company, ['reference' => 'VENDOR-INV-1']);

        $this->assertSame(2, Bill::query()->where('reference', 'VENDOR-INV-1')->count());
    }

    // ---------------------------------------------------------------
    // BillLine draft-mutability freeze
    // ---------------------------------------------------------------

    public function test_a_line_on_a_draft_bill_can_be_created_and_edited(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Draft->value]);

        $line = BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);

        $line->update(['description' => 'Updated description']);

        $this->assertSame('Updated description', $line->fresh()->description);
    }

    public function test_a_line_on_a_draft_bill_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Draft->value]);

        $line = BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);

        $line->delete();

        $this->assertSame(0, BillLine::query()->where('id', $line->id)->count());
    }

    public function test_adding_a_line_to_a_received_bill_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Received->value]);

        $this->expectException(LogicException::class);

        BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);
    }

    public function test_editing_a_line_on_a_received_bill_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Draft->value]);

        $line = BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);

        // Flip the parent to received DIRECTLY (no receive service exists
        // yet — this task is schema-only) and confirm the line is now
        // frozen. Crucially this proves the guard re-queries the parent
        // FRESH rather than relying on any cached relation captured when the
        // line was created above (see BillLine::booted()'s docblock).
        $bill->update(['status' => BillStatus::Received->value]);

        $this->expectException(LogicException::class);
        $line->update(['description' => 'Should not be allowed']);
    }

    public function test_deleting_a_line_on_a_received_bill_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Draft->value]);

        $line = BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);

        $bill->update(['status' => BillStatus::Received->value]);

        $this->expectException(LogicException::class);
        $line->delete();
    }

    public function test_editing_a_line_on_a_voided_bill_also_throws(): void
    {
        // Deliberately stricter than InvoiceLine (Phase 3), which only
        // freezes on isPosted() and NOT void — the Phase 4 brief explicitly
        // requires status !== Draft, so a voided bill's lines must stay
        // frozen too.
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company, ['status' => BillStatus::Draft->value]);

        $line = BillLine::factory()->create([
            'company_id' => $company->id,
            'bill_id' => $bill->id,
        ]);

        $bill->update(['status' => BillStatus::Void->value]);

        $this->expectException(LogicException::class);
        $line->update(['description' => 'Should not be allowed']);
    }

    // ---------------------------------------------------------------
    // Polymorphic attachments
    // ---------------------------------------------------------------

    public function test_an_attachment_can_be_created_for_a_bill(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bill = $this->bill($company);

        $attachment = $bill->attachments()->create([
            'company_id' => $company->id,
            'disk_path' => 'attachments/vendor-invoice.pdf',
            'original_name' => 'vendor-invoice.pdf',
            'mime' => 'application/pdf',
            'size' => 54321,
        ]);

        $this->assertSame(Bill::class, $attachment->attachable_type);
        $this->assertSame($bill->id, $attachment->attachable_id);
        $this->assertTrue($bill->attachments()->whereKey($attachment->id)->exists());
    }

    public function test_attachments_for_different_attachable_types_do_not_collide(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $billOne = $this->bill($company);
        $billTwo = $this->bill($company);

        $billOne->attachments()->create([
            'company_id' => $company->id,
            'disk_path' => 'attachments/one.pdf',
            'original_name' => 'one.pdf',
            'mime' => 'application/pdf',
            'size' => 111,
        ]);

        $this->assertSame(1, $billOne->attachments()->count());
        $this->assertSame(0, $billTwo->attachments()->count());
    }
}
