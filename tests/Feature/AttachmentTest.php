<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\ExpenseStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\User;
use App\Services\AttachmentService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4 Task 8 (spec §17.8 hardened): receipt/bill-scan attachments on
 * expenses + bills. Covers every hardening MUST-have — max size, content-
 * sniffed allowlist (never trusting extension/client mime), uuid-randomized
 * names on the PRIVATE local disk, authenticated+tenant-scoped+gated
 * download, nested tenant/document scoping, the upload/delete gate split,
 * "addable anytime" (even on a posted document), and an audit row for all
 * three ops — for BOTH parent document types (Expense, Bill).
 *
 * Each hardening scenario is written twice (test_expense_X / test_bill_X)
 * delegating to a shared private assertion so the two stay trivially
 * comparable, mirroring how ExpenseController/BillController themselves
 * mirror each other throughout this phase.
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ---------------------------------------------------------------
    // Fixture builders
    // ---------------------------------------------------------------

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    private function vendor(Company $company): Contact
    {
        return Contact::factory()->create([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function draftExpense(Company $company, array $overrides = []): Expense
    {
        return Expense::factory()->create(array_merge([
            'company_id' => $company->id,
            'status' => ExpenseStatus::Draft->value,
        ], $overrides));
    }

    private function recordedExpense(Company $company): Expense
    {
        return $this->draftExpense($company, [
            'status' => ExpenseStatus::Recorded->value,
            'recorded_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function draftBill(Company $company, Contact $contact, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
        ], $overrides));
    }

    private function receivedBill(Company $company, Contact $contact): Bill
    {
        return $this->draftBill($company, $contact, [
            'status' => BillStatus::Received->value,
            'received_at' => now(),
        ]);
    }

    /**
     * Builds a real, on-disk attachment via AttachmentService itself (not a
     * hand-rolled row) so download/delete fixtures always have genuine bytes
     * behind them to assert against. Runs inside its own CompanyContext
     * (mirrors BillPaymentTest::billLine/bankAccount) since AttachmentService
     * relies on it to fill company_id — independent of whoever (if anyone)
     * is currently actingAs.
     */
    private function createAttachment(Expense|Bill $model, ?string $originalName = null): Attachment
    {
        $company = Company::query()->findOrFail($model->company_id);

        return app(CompanyContext::class)->runFor(
            $company,
            fn () => app(AttachmentService::class)->store($model, $this->pdfFile($originalName ?? 'receipt.pdf')),
        );
    }

    // ---------------------------------------------------------------
    // Route helpers (kind-agnostic callers below)
    // ---------------------------------------------------------------

    private function storeRoute(Expense|Bill $model): string
    {
        return $model instanceof Expense
            ? route('expenses.attachments.store', $model->id)
            : route('bills.attachments.store', $model->id);
    }

    private function downloadRoute(Expense|Bill $model, Attachment $attachment): string
    {
        return $model instanceof Expense
            ? route('expenses.attachments.download', [$model->id, $attachment->id])
            : route('bills.attachments.download', [$model->id, $attachment->id]);
    }

    private function destroyRoute(Expense|Bill $model, Attachment $attachment): string
    {
        return $model instanceof Expense
            ? route('expenses.attachments.destroy', [$model->id, $attachment->id])
            : route('bills.attachments.destroy', [$model->id, $attachment->id]);
    }

    private function editRoute(Expense|Bill $model): string
    {
        return $model instanceof Expense
            ? route('expenses.edit', $model->id)
            : route('bills.edit', $model->id);
    }

    // ---------------------------------------------------------------
    // File builders — real bytes, never Laravel's content-less fakes, so
    // the finfo content-sniff actually exercises something meaningful.
    // ---------------------------------------------------------------

    private function pdfFile(string $name = 'receipt.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sb_pdf_');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** Genuine GD-rendered bytes (real magic numbers), not a Laravel dummy-content fake. */
    private function jpgFile(string $name = 'receipt.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function pngFile(string $name = 'receipt.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function webpFile(string $name = 'receipt.webp'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    /**
     * A file with a genuine Windows PE ("MZ") header, saved with a .pdf
     * name and an `application/pdf` client-declared mime — both of which
     * MUST be ignored. Only the sniffed bytes decide.
     */
    private function spoofedExeAsPdf(string $name = 'invoice.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sb_exe_');
        file_put_contents(
            $path,
            'MZ'.str_repeat("\x90", 58)."\x00\x00\x00\x00"."This program cannot be run in DOS mode.\x0d\x0d\x0a\x24"
        );

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function oversizedFile(string $name = 'huge.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10241, 'application/pdf');
    }

    // ---------------------------------------------------------------
    // 1. Max 10MB
    // ---------------------------------------------------------------

    public function test_expense_oversize_upload_returns_422_and_stores_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertOversizeUploadIsRejected($this->draftExpense($company));
    }

    public function test_bill_oversize_upload_returns_422_and_stores_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertOversizeUploadIsRejected($this->draftBill($company, $contact));
    }

    private function assertOversizeUploadIsRejected(Expense|Bill $model): void
    {
        $response = $this->post($this->storeRoute($model), ['file' => $this->oversizedFile()]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, Attachment::query()->count());
    }

    // ---------------------------------------------------------------
    // 2. Content sniff — a renamed .exe claiming to be .pdf must 422
    // ---------------------------------------------------------------

    public function test_expense_a_spoofed_extension_is_rejected_by_the_content_sniff(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertSpoofedUploadIsRejected($this->draftExpense($company));
    }

    public function test_bill_a_spoofed_extension_is_rejected_by_the_content_sniff(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertSpoofedUploadIsRejected($this->draftBill($company, $contact));
    }

    private function assertSpoofedUploadIsRejected(Expense|Bill $model): void
    {
        $response = $this->post($this->storeRoute($model), ['file' => $this->spoofedExeAsPdf()]);

        $response->assertSessionHasErrors('file');
        $this->assertSame(0, Attachment::query()->count());
        // Never even wrote bytes to disk — the sniff rejects before storeAs().
        Storage::disk('local')->assertDirectoryEmpty("attachments/{$model->company_id}");
    }

    // ---------------------------------------------------------------
    // 3. Valid pdf/jpg upload — random uuid name on the PRIVATE disk
    // ---------------------------------------------------------------

    public function test_expense_valid_pdf_upload_is_stored_with_a_random_name_on_the_private_disk(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertValidUploadIsStoredPrivately($this->draftExpense($company), Expense::class);
    }

    public function test_bill_valid_pdf_upload_is_stored_with_a_random_name_on_the_private_disk(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertValidUploadIsStoredPrivately($this->draftBill($company, $contact), Bill::class);
    }

    private function assertValidUploadIsStoredPrivately(Expense|Bill $model, string $expectedType): void
    {
        $response = $this->post($this->storeRoute($model), ['file' => $this->pdfFile('my receipt.pdf')]);

        $response->assertRedirect($this->editRoute($model));

        $attachment = Attachment::query()->sole();
        $this->assertSame($expectedType, $attachment->attachable_type);
        $this->assertSame($model->id, $attachment->attachable_id);
        $this->assertSame('my receipt.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertGreaterThan(0, $attachment->size);

        // uuid-randomized name under a company-scoped path — never the
        // client's original filename.
        $this->assertMatchesRegularExpression(
            '#^attachments/'.$model->company_id.'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#',
            $attachment->disk_path,
        );
        $this->assertStringNotContainsString('my receipt', $attachment->disk_path);

        // On the PRIVATE `local` disk, not `public`.
        Storage::disk('local')->assertExists($attachment->disk_path);
        Storage::disk('public')->assertMissing($attachment->disk_path);

        $audit = ActivityLog::query()->where('action', 'attachment.uploaded')->sole();
        $this->assertSame($attachment->id, $audit->subject_id);
        $this->assertSame('my receipt.pdf', $audit->after['original_name']);
    }

    public function test_expense_valid_jpg_upload_is_also_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertValidJpgUploadIsAccepted($this->draftExpense($company));
    }

    public function test_bill_valid_jpg_upload_is_also_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertValidJpgUploadIsAccepted($this->draftBill($company, $contact));
    }

    private function assertValidJpgUploadIsAccepted(Expense|Bill $model): void
    {
        $response = $this->post($this->storeRoute($model), ['file' => $this->jpgFile('scan.jpg')]);

        $response->assertRedirect($this->editRoute($model));

        $attachment = Attachment::query()->sole();
        $this->assertSame('image/jpeg', $attachment->mime);
        $this->assertStringEndsWith('.jpg', $attachment->disk_path);
        Storage::disk('local')->assertExists($attachment->disk_path);
    }

    /**
     * Full allowlist sweep (spec §17.8: pdf/jpg/png/webp) — one test per
     * parent, looping the four content types rather than four more test
     * methods each.
     */
    public function test_expense_the_full_allowlist_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertFullAllowlistIsAccepted($company, fn () => $this->draftExpense($company));
    }

    public function test_bill_the_full_allowlist_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertFullAllowlistIsAccepted($company, fn () => $this->draftBill($company, $contact));
    }

    private function assertFullAllowlistIsAccepted(Company $company, \Closure $buildModel): void
    {
        $builders = [
            'application/pdf' => fn () => $this->pdfFile('x.pdf'),
            'image/jpeg' => fn () => $this->jpgFile('x.jpg'),
            'image/png' => fn () => $this->pngFile('x.png'),
            'image/webp' => fn () => $this->webpFile('x.webp'),
        ];

        foreach ($builders as $expectedMime => $builder) {
            $model = $buildModel();
            $this->post($this->storeRoute($model), ['file' => $builder()])->assertRedirect();

            $attachment = Attachment::query()->where('attachable_id', $model->id)->sole();
            $this->assertSame($expectedMime, $attachment->mime);
        }
    }

    // ---------------------------------------------------------------
    // 4. Download — streamed, correct content-type + original filename
    // ---------------------------------------------------------------

    public function test_expense_download_streams_the_original_bytes_with_filename_and_content_type(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertDownloadStreamsCorrectly($this->draftExpense($company));
    }

    public function test_bill_download_streams_the_original_bytes_with_filename_and_content_type(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertDownloadStreamsCorrectly($this->draftBill($company, $contact));
    }

    private function assertDownloadStreamsCorrectly(Expense|Bill $model): void
    {
        $this->post($this->storeRoute($model), ['file' => $this->pdfFile('vendor invoice.pdf')])->assertRedirect();
        $attachment = Attachment::query()->sole();

        $response = $this->get($this->downloadRoute($model, $attachment));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('vendor invoice.pdf', (string) $response->headers->get('Content-Disposition'));

        $audit = ActivityLog::query()->where('action', 'attachment.downloaded')->sole();
        $this->assertSame($attachment->id, $audit->subject_id);
    }

    // ---------------------------------------------------------------
    // 5. Guest blocked
    // ---------------------------------------------------------------

    public function test_expense_guest_is_redirected_to_login_on_download_and_upload(): void
    {
        $company = Company::factory()->create();
        $this->assertGuestIsBlocked($this->draftExpenseWithoutActor($company));
    }

    public function test_bill_guest_is_redirected_to_login_on_download_and_upload(): void
    {
        $company = Company::factory()->create();
        $contact = $this->vendor($company);
        $this->assertGuestIsBlocked($this->draftBill($company, $contact));
    }

    /** Builds a draft expense without any acting user — mirrors InvoicePdfTest::test_guest_is_redirected_to_login. */
    private function draftExpenseWithoutActor(Company $company): Expense
    {
        return $this->draftExpense($company);
    }

    /**
     * No authenticated request is ever made in this test — SetCompanyContext
     * (the middleware that normally populates CompanyContext) never runs, so
     * the final tenant-scoped count below must supply its own context, same
     * as createAttachment() does for building the fixture.
     */
    private function assertGuestIsBlocked(Expense|Bill $model): void
    {
        $attachment = $this->createAttachment($model);

        $this->get($this->downloadRoute($model, $attachment))->assertRedirect(route('login'));
        $this->post($this->storeRoute($model), ['file' => $this->pdfFile()])->assertRedirect(route('login'));

        $company = Company::query()->findOrFail($model->company_id);
        $count = app(CompanyContext::class)->runFor($company, fn () => Attachment::query()->count());
        $this->assertSame(1, $count);
    }

    // ---------------------------------------------------------------
    // 6. Foreign tenant 404
    // ---------------------------------------------------------------

    public function test_expense_a_foreign_companys_attachment_404s_on_download(): void
    {
        $companyA = Company::factory()->create();
        $expenseA = $this->draftExpense($companyA);
        $attachment = $this->createAttachment($expenseA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->get($this->downloadRoute($expenseA, $attachment))->assertNotFound();
    }

    public function test_bill_a_foreign_companys_attachment_404s_on_download(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->vendor($companyA);
        $billA = $this->draftBill($companyA, $contactA);
        $attachment = $this->createAttachment($billA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->get($this->downloadRoute($billA, $attachment))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 7. Nested scoped 404 — attachment belongs to a DIFFERENT document
    // (same company) — mirrors BillPaymentVoidTest's identical pattern.
    // ---------------------------------------------------------------

    public function test_expense_an_attachment_belonging_to_a_different_expense_404s(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $expenseA = $this->draftExpense($company);
        $expenseB = $this->draftExpense($company);

        $attachment = $this->createAttachment($expenseA);

        $this->get($this->downloadRoute($expenseB, $attachment))->assertNotFound();
    }

    public function test_bill_an_attachment_belonging_to_a_different_bill_404s(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $billA = $this->draftBill($company, $contact);
        $billB = $this->draftBill($company, $contact);

        $attachment = $this->createAttachment($billA);

        $this->get($this->downloadRoute($billB, $attachment))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 8. Cross-parent 404 — an expense's attachment via the BILL route
    // ---------------------------------------------------------------

    public function test_an_expense_attachment_is_not_reachable_via_the_bill_route(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $expense = $this->draftExpense($company);
        $bill = $this->draftBill($company, $contact);

        $attachment = $this->createAttachment($expense);

        $this->get(route('bills.attachments.download', [$bill->id, $attachment->id]))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 9. Delete gate — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_expense_owner_admin_and_accountant_can_delete_and_the_file_is_removed(): void
    {
        $this->assertPrivilegedRolesCanDelete(fn (Company $company) => $this->draftExpense($company));
    }

    public function test_bill_owner_admin_and_accountant_can_delete_and_the_file_is_removed(): void
    {
        $this->assertPrivilegedRolesCanDelete(function (Company $company) {
            $contact = $this->vendor($company);

            return $this->draftBill($company, $contact);
        });
    }

    private function assertPrivilegedRolesCanDelete(\Closure $buildModel): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $model = $buildModel($company);
            $attachment = $this->createAttachment($model);
            $diskPath = $attachment->disk_path;

            $this->delete($this->destroyRoute($model, $attachment))
                ->assertRedirect($this->editRoute($model));

            $this->assertSame(0, Attachment::query()->count());
            Storage::disk('local')->assertMissing($diskPath);
        }
    }

    public function test_expense_staff_is_forbidden_from_deleting_an_attachment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $model = $this->draftExpense($company);
        $attachment = $this->createAttachment($model);

        $this->actingAsRole($company, Role::Staff);

        $this->delete($this->destroyRoute($model, $attachment))->assertForbidden();
        $this->assertSame(1, Attachment::query()->count());
        Storage::disk('local')->assertExists($attachment->disk_path);
    }

    public function test_bill_staff_is_forbidden_from_deleting_an_attachment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $model = $this->draftBill($company, $contact);
        $attachment = $this->createAttachment($model);

        $this->actingAsRole($company, Role::Staff);

        $this->delete($this->destroyRoute($model, $attachment))->assertForbidden();
        $this->assertSame(1, Attachment::query()->count());
        Storage::disk('local')->assertExists($attachment->disk_path);
    }

    // ---------------------------------------------------------------
    // 10. Staff CAN upload — expenses.draft/bills.draft is all four roles
    // ---------------------------------------------------------------

    public function test_expense_staff_can_upload_an_attachment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->assertStaffCanUpload($this->draftExpense($company));
    }

    public function test_bill_staff_can_upload_an_attachment(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = $this->vendor($company);

        $this->assertStaffCanUpload($this->draftBill($company, $contact));
    }

    private function assertStaffCanUpload(Expense|Bill $model): void
    {
        $this->post($this->storeRoute($model), ['file' => $this->pdfFile()])
            ->assertRedirect($this->editRoute($model));

        $this->assertSame(1, Attachment::query()->count());
    }

    // ---------------------------------------------------------------
    // 11. Addable anytime — even on a posted document
    // ---------------------------------------------------------------

    public function test_expense_upload_is_allowed_on_a_recorded_expense(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $expense = $this->recordedExpense($company);

        $this->post($this->storeRoute($expense), ['file' => $this->pdfFile()])
            ->assertRedirect($this->editRoute($expense));

        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_bill_upload_is_allowed_on_a_received_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);
        $bill = $this->receivedBill($company, $contact);

        $this->post($this->storeRoute($bill), ['file' => $this->pdfFile()])
            ->assertRedirect($this->editRoute($bill));

        $this->assertSame(1, Attachment::query()->count());
    }

    // ---------------------------------------------------------------
    // 12. Audit rows — upload, download, AND delete each log
    // ---------------------------------------------------------------

    public function test_expense_upload_download_and_delete_each_write_an_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertFullLifecycleIsAudited($this->draftExpense($company));
    }

    public function test_bill_upload_download_and_delete_each_write_an_audit_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertFullLifecycleIsAudited($this->draftBill($company, $contact));
    }

    private function assertFullLifecycleIsAudited(Expense|Bill $model): void
    {
        $this->post($this->storeRoute($model), ['file' => $this->pdfFile()])->assertRedirect();
        $attachment = Attachment::query()->sole();
        $this->assertSame(1, ActivityLog::query()->where('action', 'attachment.uploaded')->count());

        $this->get($this->downloadRoute($model, $attachment))->assertOk();
        $this->assertSame(1, ActivityLog::query()->where('action', 'attachment.downloaded')->count());

        $this->delete($this->destroyRoute($model, $attachment))->assertRedirect();
        $this->assertSame(1, ActivityLog::query()->where('action', 'attachment.deleted')->count());

        $deleteAudit = ActivityLog::query()->where('action', 'attachment.deleted')->sole();
        $this->assertSame($attachment->id, $deleteAudit->subject_id);
    }

    // ---------------------------------------------------------------
    // 13. Draft delete cascades attachments — no orphan rows, no leaked
    // files. Deleting a draft that had a receipt uploaded must remove the
    // Attachment rows AND the private disk files, alongside the lines the
    // destroy() methods already cascaded (reviewer finding, this task).
    // ---------------------------------------------------------------

    public function test_expense_deleting_a_draft_cascades_its_attachments_rows_and_files(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->assertDraftDeleteCascadesAttachments($this->draftExpense($company));
    }

    public function test_bill_deleting_a_draft_cascades_its_attachments_rows_and_files(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->vendor($company);

        $this->assertDraftDeleteCascadesAttachments($this->draftBill($company, $contact));
    }

    private function assertDraftDeleteCascadesAttachments(Expense|Bill $model): void
    {
        // Two attachments, to prove the cascade isn't accidentally
        // first-row-only.
        $first = $this->createAttachment($model, 'receipt one.pdf');
        $second = $this->createAttachment($model, 'receipt two.pdf');
        Storage::disk('local')->assertExists($first->disk_path);
        Storage::disk('local')->assertExists($second->disk_path);

        $deleteRoute = $model instanceof Expense
            ? route('expenses.destroy', $model->id)
            : route('bills.destroy', $model->id);
        $indexRoute = $model instanceof Expense ? route('expenses.index') : route('bills.index');

        $this->delete($deleteRoute)->assertRedirect($indexRoute);

        // The draft itself is gone...
        $parentQuery = $model instanceof Expense ? Expense::query() : Bill::query();
        $this->assertSame(0, $parentQuery->count());

        // ...and so are BOTH attachment rows and BOTH private disk files —
        // no orphan row with a dangling attachable_id, no leaked bytes.
        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($first->disk_path);
        Storage::disk('local')->assertMissing($second->disk_path);

        // One draft_deleted audit row covers the cascade — deliberately no
        // per-receipt attachment.deleted rows from this path (see
        // AttachmentService::deleteAllFor's docblock).
        $expectedAction = $model instanceof Expense ? 'expense.draft_deleted' : 'bill.draft_deleted';
        $this->assertSame(1, ActivityLog::query()->where('action', $expectedAction)->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'attachment.deleted')->count());
    }
}
