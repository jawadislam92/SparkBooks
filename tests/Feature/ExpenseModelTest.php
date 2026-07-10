<?php

namespace Tests\Feature;

use App\Enums\ExpenseStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ExpenseModelTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function expense(Company $company, array $overrides = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // ExpenseStatus enum: cast + helpers
    // ---------------------------------------------------------------

    public function test_status_casts_to_the_enum_and_helpers_are_correct(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $draft = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);
        $this->assertSame(ExpenseStatus::Draft, $draft->status);
        $this->assertTrue($draft->status->canEdit());
        $this->assertTrue($draft->status->canRecord());
        $this->assertFalse($draft->status->isPosted());
        $this->assertFalse($draft->status->canVoid());

        $recorded = $this->expense($company, ['status' => ExpenseStatus::Recorded->value]);
        $this->assertTrue($recorded->status->isPosted());
        $this->assertFalse($recorded->status->canEdit());
        $this->assertFalse($recorded->status->canRecord());
        $this->assertTrue($recorded->status->canVoid());

        $void = $this->expense($company, ['status' => ExpenseStatus::Void->value]);
        $this->assertFalse($void->status->isPosted());
        $this->assertFalse($void->status->canEdit());
        $this->assertFalse($void->status->canRecord());
        $this->assertFalse($void->status->canVoid());
    }

    // ---------------------------------------------------------------
    // contact_id: nullable (optional payee) + composite tenant FK
    // ---------------------------------------------------------------

    public function test_contact_id_is_nullable_at_the_database_level(): void
    {
        $company = Company::factory()->create();

        DB::table('expenses')->insert([
            'company_id' => $company->id,
            'contact_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('expenses')->where('company_id', $company->id)->whereNull('contact_id')->count());
    }

    public function test_an_expense_can_be_created_with_no_contact(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $expense = $this->expense($company, ['contact_id' => null]);

        $this->assertNull($expense->contact_id);
    }

    public function test_composite_fk_rejects_a_contact_from_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignContact = Contact::factory()->create(['company_id' => $companyB->id]);

        $this->expectException(QueryException::class);

        DB::table('expenses')->insert([
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

        DB::table('expenses')->insert([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('expenses')->where('contact_id', $contact->id)->count());
    }

    // ---------------------------------------------------------------
    // ExpenseLine draft-mutability freeze
    // ---------------------------------------------------------------

    public function test_a_line_on_a_draft_expense_can_be_created_and_edited(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);

        $line = ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);

        $line->update(['description' => 'Updated description']);

        $this->assertSame('Updated description', $line->fresh()->description);
    }

    public function test_a_line_on_a_draft_expense_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);

        $line = ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);

        $line->delete();

        $this->assertSame(0, ExpenseLine::query()->where('id', $line->id)->count());
    }

    public function test_adding_a_line_to_a_recorded_expense_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Recorded->value]);

        $this->expectException(LogicException::class);

        ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);
    }

    public function test_editing_a_line_on_a_recorded_expense_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);

        $line = ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);

        // Flip the parent to recorded DIRECTLY (no record service exists yet
        // — this task is schema-only) and confirm the line is now frozen.
        // Crucially this proves the guard re-queries the parent FRESH rather
        // than relying on any cached relation captured when the line was
        // created above (see ExpenseLine::booted()'s docblock).
        $expense->update(['status' => ExpenseStatus::Recorded->value]);

        $this->expectException(LogicException::class);
        $line->update(['description' => 'Should not be allowed']);
    }

    public function test_deleting_a_line_on_a_recorded_expense_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);

        $line = ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);

        $expense->update(['status' => ExpenseStatus::Recorded->value]);

        $this->expectException(LogicException::class);
        $line->delete();
    }

    public function test_editing_a_line_on_a_voided_expense_also_throws(): void
    {
        // Deliberately stricter than InvoiceLine (Phase 3), which only
        // freezes on isPosted() and NOT void — the Phase 4 brief explicitly
        // requires status !== Draft, so a voided expense's lines must stay
        // frozen too.
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company, ['status' => ExpenseStatus::Draft->value]);

        $line = ExpenseLine::factory()->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
        ]);

        $expense->update(['status' => ExpenseStatus::Void->value]);

        $this->expectException(LogicException::class);
        $line->update(['description' => 'Should not be allowed']);
    }

    // ---------------------------------------------------------------
    // Polymorphic attachments
    // ---------------------------------------------------------------

    public function test_an_attachment_can_be_created_for_an_expense(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $expense = $this->expense($company);

        $attachment = $expense->attachments()->create([
            'company_id' => $company->id,
            'disk_path' => 'attachments/receipt.pdf',
            'original_name' => 'receipt.pdf',
            'mime' => 'application/pdf',
            'size' => 12345,
        ]);

        $this->assertSame(Expense::class, $attachment->attachable_type);
        $this->assertSame($expense->id, $attachment->attachable_id);
        $this->assertTrue($expense->attachments()->whereKey($attachment->id)->exists());
    }
}
