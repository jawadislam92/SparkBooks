<?php

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\EmailAttempt;
use App\Models\Invoice;
use App\Services\InvoicePdf;
use App\Support\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * §17.6 decoupled email delivery. InvoiceService::sendEmail()/resendEmail()
 * flip email_status to 'queued' and dispatch this job AFTER their own
 * transaction commits — this job therefore runs entirely OUTSIDE any
 * ledger-affecting transaction and can NEVER roll back a posting. It touches
 * exactly one Invoice column (email_status) plus writes one EmailAttempt
 * row; nothing here EVER mutates the invoice's status, amount_paid, balance,
 * or the ledger. Both the PDF render and the mail send are wrapped in the
 * SAME try/catch (spec §17.6: "a rendering failure surfaces ... a `failed`
 * email attempt — the invoice itself remains valid") — a Throwable from
 * either step is caught, logged, and turned into a failed EmailAttempt
 * rather than allowed to bubble out of handle(). The `failed` email_status
 * plus the logged attempt IS how the failure surfaces (invoice page /
 * dashboard), not an uncaught job exception.
 *
 * Carries invoice_id + company_id — NOT a bound Invoice model — because a
 * queued job runs on a worker process with no CompanyContext yet; the first
 * thing handle() does is establish one from the carried company_id, the same
 * pattern VerifyLedgerIntegrity uses for the same reason (console/queue
 * contexts have no request-scoped middleware to set it).
 *
 * Idempotent-ish, not strictly idempotent: re-running this job (a queue
 * retry, or a deliberate Resend) creates ANOTHER EmailAttempt row and
 * overwrites email_status again — it does NOT deduplicate attempts. That's
 * intentional: every run is a real send (or a real failure) worth its own
 * log line. Only the ledger needs strict once-only idempotency (spec
 * §17.2's IdempotencyService); this job never touches the ledger, so that
 * machinery doesn't apply here.
 */
class SendInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {}

    public function handle(CompanyContext $context, InvoicePdf $pdfService): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $context->runFor($company, function () use ($pdfService) {
            $invoice = Invoice::query()->with(['contact', 'company'])->findOrFail($this->invoiceId);
            $recipient = $invoice->contact->email;

            try {
                $pdfContent = $pdfService->render($invoice)->output();

                Mail::to($recipient)->send(new InvoiceMail($invoice, $pdfContent));

                $invoice->forceFill(['email_status' => 'sent'])->save();

                EmailAttempt::query()->create([
                    'invoice_id' => $invoice->id,
                    'recipient' => (string) $recipient,
                    'status' => 'sent',
                    'error' => null,
                    'attempted_at' => now(),
                ]);
            } catch (Throwable $e) {
                $invoice->forceFill(['email_status' => 'failed'])->save();

                EmailAttempt::query()->create([
                    'invoice_id' => $invoice->id,
                    'recipient' => (string) $recipient,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'attempted_at' => now(),
                ]);

                Log::error('SendInvoiceEmail failed', [
                    'invoice_id' => $invoice->id,
                    'company_id' => $this->companyId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
