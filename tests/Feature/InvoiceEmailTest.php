<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceMail;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailAttempt;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\InvoicePdf;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 3 Task 10 (spec §17.6): emailing an invoice, with delivery status
 * fully DECOUPLED from the ledger/invoice posted state. Every scenario here
 * drives a REAL send() HTTP flow first (never a hand-rolled Sent row) so
 * there's a genuine P1 JournalEntry on the books whose untouchedness after
 * an email failure is the entire point of this file (see the CRITICAL
 * section below).
 */
class InvoiceEmailTest extends TestCase
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

    private function chart(Company $company): void
    {
        $this->arAccount($company);
        $this->taxPayableAccount($company);
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
     * Wrapped in CompanyContext::runFor — mirrors InvoiceSendTest's own
     * helper (InvoiceLine::booted()'s freeze guard re-queries the invoice
     * under the tenant scope on every create).
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

    private function sendInvoiceHttp(Invoice $invoice, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/send", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function voidInvoiceHttp(Invoice $invoice, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/void", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /** @param array<string, string> $headers */
    private function emailInvoiceHttp(Invoice $invoice, ?string $key = null, array $headers = []): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/email", [], array_merge([
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ], $headers));
    }

    /** @param array<string, string> $headers */
    private function resendInvoiceHttp(Invoice $invoice, ?string $key = null, array $headers = []): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/email/resend", [], array_merge([
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ], $headers));
    }

    /**
     * Full real posting flow: draft -> one line -> send() -> a genuine
     * Sent invoice with a genuine P1 JournalEntry on the books. Returns the
     * invoice plus everything a "did the ledger stay untouched" assertion
     * needs to compare against.
     *
     * @return array{invoice: Invoice, contact: Contact, company: Company, entryId: int}
     */
    private function postedInvoice(): array
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);

        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 100000]);

        $this->sendInvoiceHttp($invoice)->assertRedirect();

        $entry = JournalEntry::query()
            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->sole();

        return [
            'invoice' => $invoice->fresh(),
            'contact' => $contact,
            'company' => $company,
            'entryId' => $entry->id,
        ];
    }

    // ---------------------------------------------------------------
    // The decoupling itself (spec §17.6): queue on sendEmail, sent on a
    // successful job run.
    // ---------------------------------------------------------------

    public function test_send_email_queues_the_job_and_sets_email_status_queued(): void
    {
        ['invoice' => $invoice, 'company' => $company] = $this->postedInvoice();

        Queue::fake();

        $this->emailInvoiceHttp($invoice)->assertRedirect();

        Queue::assertPushed(SendInvoiceEmail::class, function (SendInvoiceEmail $job) use ($invoice, $company) {
            return $job->invoiceId === $invoice->id && $job->companyId === $company->id;
        });

        $this->assertSame('queued', $invoice->fresh()->email_status);
        // Queue::fake() intercepted the dispatch, so the job never actually
        // ran — no EmailAttempt has been logged yet.
        $this->assertSame(0, EmailAttempt::query()->count());
    }

    // ---------------------------------------------------------------
    // Idempotency (spec §17.6: "idempotency per §17.2 prevents double-dispatch
    // of the same attempt"). A double-submit of ONE press (same key) must
    // dispatch ONE job / send ONE email; a genuine later Resend (fresh key)
    // dispatches again.
    // ---------------------------------------------------------------

    public function test_a_double_submit_with_the_same_key_dispatches_the_job_once(): void
    {
        ['invoice' => $invoice] = $this->postedInvoice();

        Queue::fake();

        $key = (string) Str::uuid();
        // Same key twice — a double-click / network retry of ONE Email press.
        $this->emailInvoiceHttp($invoice, $key)->assertRedirect();
        $this->emailInvoiceHttp($invoice, $key)->assertRedirect();

        // Exactly ONE job dispatched despite two requests — the customer never
        // gets two copies of the same email.
        Queue::assertPushed(SendInvoiceEmail::class, 1);
        // And exactly one audit row — the second request replayed the first's
        // stored result without re-running the guard/flip/audit.
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.email_queued')->count());
        $this->assertSame('queued', $invoice->fresh()->email_status);

        // A genuine later Resend is a SEPARATE press with a FRESH key — it
        // dispatches again (the key dedupes accidental double-submits, not
        // intentional resends).
        $this->resendInvoiceHttp($invoice, (string) Str::uuid())->assertRedirect();
        Queue::assertPushed(SendInvoiceEmail::class, 2);
        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.email_resent')->count());
    }

    public function test_email_requires_a_valid_idempotency_key(): void
    {
        ['invoice' => $invoice] = $this->postedInvoice();

        Queue::fake();

        // Missing header entirely.
        $this->postJson("/invoices/{$invoice->id}/email")
            ->assertSessionHasErrors('idempotency_key');
        // Present but not a UUID.
        $this->emailInvoiceHttp($invoice, key: null, headers: ['X-Idempotency-Key' => 'not-a-uuid'])
            ->assertSessionHasErrors('idempotency_key');

        Queue::assertNothingPushed();
        $this->assertSame('not_sent', $invoice->fresh()->email_status);
    }

    public function test_resend_requires_a_valid_idempotency_key(): void
    {
        ['invoice' => $invoice] = $this->postedInvoice();

        Queue::fake();

        $this->postJson("/invoices/{$invoice->id}/email/resend")
            ->assertSessionHasErrors('idempotency_key');

        Queue::assertNothingPushed();
    }

    public function test_running_the_job_on_success_sets_sent_and_logs_an_attempt(): void
    {
        ['invoice' => $invoice, 'contact' => $contact, 'company' => $company] = $this->postedInvoice();

        Mail::fake();

        // No Queue::fake() here — QUEUE_CONNECTION=sync (phpunit.xml) means
        // this dispatch runs SendInvoiceEmail::handle() synchronously, right
        // here, inline with the request.
        $this->emailInvoiceHttp($invoice)->assertRedirect();

        Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) use ($invoice, $contact) {
            return $mail->invoice->id === $invoice->id && $mail->hasTo($contact->email);
        });

        $invoice->refresh();
        $this->assertSame('sent', $invoice->email_status);
        // The email is fully decoupled: it never touches the invoice's
        // posted state.
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $attempt = EmailAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('sent', $attempt->status);
        $this->assertSame($contact->email, $attempt->recipient);
        $this->assertNull($attempt->error);
        $this->assertNotNull($attempt->attempted_at);
        $this->assertSame($company->id, $attempt->company_id);

        $audit = ActivityLog::query()->where('action', 'invoice.email_queued')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
    }

    // ---------------------------------------------------------------
    // THE §17.6 PROPERTY: a mail transport failure never touches the
    // ledger or the invoice's posted state.
    // ---------------------------------------------------------------

    public function test_a_mail_transport_failure_keeps_the_invoice_posted_and_the_ledger_untouched(): void
    {
        ['invoice' => $invoice, 'contact' => $contact, 'company' => $company, 'entryId' => $entryId] = $this->postedInvoice();

        // Snapshot everything the ledger/invoice posted state must NOT move.
        $before = [
            'status' => $invoice->status,
            'amount_paid' => $invoice->amount_paid,
            'balance' => $invoice->balance,
            'total' => $invoice->total,
            'base_total' => $invoice->base_total,
            'number' => $invoice->number,
        ];
        $entryBefore = JournalEntry::query()->with('lines')->findOrFail($entryId);
        $linesBefore = $entryBefore->lines->map(fn ($l) => [$l->account_id, $l->debit, $l->credit])->all();

        // Kill the mail transport: Mail::to(...)->send(...) throws.
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Mail transport down'));

        $this->emailInvoiceHttp($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame('failed', $invoice->email_status);

        $attempt = EmailAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame($contact->email, $attempt->recipient);
        $this->assertStringContainsString('Mail transport down', $attempt->error);

        // --- The ledger/invoice posted state is UNCHANGED. ---
        $this->assertSame($before['status'], $invoice->status);
        $this->assertSame($before['amount_paid'], $invoice->amount_paid);
        $this->assertSame($before['balance'], $invoice->balance);
        $this->assertSame($before['total'], $invoice->total);
        $this->assertSame($before['base_total'], $invoice->base_total);
        $this->assertSame($before['number'], $invoice->number);

        $entryAfter = JournalEntry::query()->with('lines')->findOrFail($entryId);
        $linesAfter = $entryAfter->lines->map(fn ($l) => [$l->account_id, $l->debit, $l->credit])->all();
        $this->assertSame($linesBefore, $linesAfter);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count());

        $this->assertLedgerBalances();
    }

    public function test_pdf_render_failure_is_a_failed_attempt_and_the_invoice_stays_valid(): void
    {
        ['invoice' => $invoice, 'contact' => $contact] = $this->postedInvoice();

        Mail::fake();
        $this->mock(InvoicePdf::class, function ($mock) {
            $mock->shouldReceive('render')->andThrow(new \RuntimeException('PDF render exploded'));
        });

        $this->emailInvoiceHttp($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame('failed', $invoice->email_status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $attempt = EmailAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame($contact->email, $attempt->recipient);
        $this->assertStringContainsString('PDF render exploded', $attempt->error);

        Mail::assertNothingSent();
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Resend
    // ---------------------------------------------------------------

    public function test_resend_after_a_failure_requeues_and_logs_a_new_attempt(): void
    {
        ['invoice' => $invoice, 'contact' => $contact] = $this->postedInvoice();

        // Seed a prior FAILED attempt directly (rather than re-deriving it
        // via Mail::shouldReceive in this same test, which would leave a
        // Mockery expectation active alongside the Mail::fake() below) — the
        // ambient CompanyContext is already the right tenant here, set by
        // the 'company' middleware during postedInvoice()'s own HTTP calls.
        EmailAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'recipient' => $contact->email,
            'status' => 'failed',
            'error' => 'Mail transport down (seeded)',
            'attempted_at' => now(),
        ]);
        $invoice->forceFill(['email_status' => 'failed'])->save();
        $this->assertSame(1, EmailAttempt::query()->count());

        Mail::fake();

        $this->resendInvoiceHttp($invoice)->assertRedirect();

        $invoice->refresh();
        $this->assertSame('sent', $invoice->email_status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $this->assertSame(2, EmailAttempt::query()->where('invoice_id', $invoice->id)->count());
        $latest = EmailAttempt::query()->where('invoice_id', $invoice->id)->orderByDesc('id')->first();
        $this->assertSame('sent', $latest->status);
        $this->assertSame($contact->email, $latest->recipient);

        $audit = ActivityLog::query()->where('action', 'invoice.email_resent')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
    }

    // ---------------------------------------------------------------
    // Guard: only a posted invoice can be emailed — draft/void -> 422.
    // ---------------------------------------------------------------

    public function test_emailing_a_draft_invoice_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $invoice = $this->draftInvoice($company, $contact);

        $response = $this->emailInvoiceHttp($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame('not_sent', $invoice->fresh()->email_status);
        $this->assertSame(0, EmailAttempt::query()->count());
    }

    public function test_emailing_a_void_invoice_returns_422(): void
    {
        ['invoice' => $invoice] = $this->postedInvoice();

        $this->voidInvoiceHttp($invoice)->assertRedirect();
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);

        $response = $this->emailInvoiceHttp($invoice);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, EmailAttempt::query()->count());
    }

    public function test_resending_a_draft_invoice_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $invoice = $this->draftInvoice($company, $contact);

        $response = $this->resendInvoiceHttp($invoice);

        $response->assertSessionHasErrors('status');
    }

    // ---------------------------------------------------------------
    // Gate: invoices.send — Staff forbidden.
    // ---------------------------------------------------------------

    public function test_staff_is_forbidden_from_emailing_an_invoice(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->chart($company);
        $income = $this->incomeAccount($company);
        $invoice = $this->draftInvoice($company, $contact);
        $this->invoiceLine($invoice, $income, ['quantity' => '1', 'unit_price' => 10000]);
        $this->sendInvoiceHttp($invoice)->assertRedirect();

        // Switch to a Staff user in the SAME company.
        $this->actingAsRole($company, Role::Staff);

        // BOTH the email and resend routes share the can:invoices.send gate —
        // assert each independently rather than trusting the shared middleware.
        $this->emailInvoiceHttp($invoice)->assertForbidden();
        $this->resendInvoiceHttp($invoice)->assertForbidden();
        $this->assertSame(0, EmailAttempt::query()->count());
    }

    // ---------------------------------------------------------------
    // Scoped: a foreign invoice 404s.
    // ---------------------------------------------------------------

    public function test_a_foreign_invoice_404s_on_email_and_resend(): void
    {
        $companyA = Company::factory()->create();
        $contactA = $this->customer($companyA);
        $this->chart($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->actingAsRole($companyA, Role::Owner);
        $foreignInvoice = $this->draftInvoice($companyA, $contactA);
        $this->invoiceLine($foreignInvoice, $incomeA, ['quantity' => '1', 'unit_price' => 10000]);
        $this->sendInvoiceHttp($foreignInvoice)->assertRedirect();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        // Both routes are tenant-scoped — assert each independently.
        $this->emailInvoiceHttp($foreignInvoice)->assertNotFound();
        $this->resendInvoiceHttp($foreignInvoice)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // The job establishes its OWN CompanyContext (it runs on a worker with
    // none), independent of whatever ambient context a request happens to
    // have set.
    // ---------------------------------------------------------------

    public function test_the_job_establishes_its_own_company_context_when_run_outside_a_request(): void
    {
        ['invoice' => $invoice, 'contact' => $contact, 'company' => $company] = $this->postedInvoice();

        Mail::fake();

        // Simulate a REAL queue worker process: no ambient CompanyContext at
        // all (a worker never ran the 'company' HTTP middleware).
        app(CompanyContext::class)->clear();
        $this->assertFalse(app(CompanyContext::class)->has());

        (new SendInvoiceEmail($company->id, $invoice->id))
            ->handle(app(CompanyContext::class), app(InvoicePdf::class));

        // runFor()'s finally restores the ambient state (still "none") —
        // proving the job's context-setting was entirely self-contained.
        $this->assertFalse(app(CompanyContext::class)->has());

        // Raw, unscoped queries — no CompanyContext is set right now.
        $attemptRow = DB::table('email_attempts')->where('invoice_id', $invoice->id)->sole();
        $this->assertSame($company->id, $attemptRow->company_id);
        $this->assertSame('sent', $attemptRow->status);
        $this->assertSame($contact->email, $attemptRow->recipient);

        $invoiceRow = DB::table('invoices')->where('id', $invoice->id)->sole();
        $this->assertSame('sent', $invoiceRow->email_status);
        $this->assertSame('sent', $invoiceRow->status);
    }

    // ---------------------------------------------------------------
    // Idempotent-ish: re-running the job is safe — a retry creates another
    // attempt but never corrupts the invoice's posted state.
    // ---------------------------------------------------------------

    public function test_rerunning_the_job_creates_another_attempt_without_corrupting_invoice_state(): void
    {
        ['invoice' => $invoice, 'contact' => $contact, 'company' => $company, 'entryId' => $entryId] = $this->postedInvoice();

        Mail::fake();

        $job = new SendInvoiceEmail($company->id, $invoice->id);
        $job->handle(app(CompanyContext::class), app(InvoicePdf::class));
        $job->handle(app(CompanyContext::class), app(InvoicePdf::class));

        $this->assertSame(2, EmailAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertTrue(
            EmailAttempt::query()->where('invoice_id', $invoice->id)->where('status', 'sent')->count() === 2
        );

        $invoice->refresh();
        $this->assertSame('sent', $invoice->email_status);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);

        $entry = JournalEntry::query()->with('lines')->findOrFail($entryId);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }
}
