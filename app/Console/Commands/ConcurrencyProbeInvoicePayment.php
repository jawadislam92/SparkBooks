<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEST-SUPPORT ONLY — not a product feature, never wired into any user-facing
 * flow. Spawned as a genuinely separate OS process (its own PHP interpreter,
 * its own MySQL connection) by tests/Feature/InvoicePaymentConcurrencyTest to
 * prove the spec §12.6/§9 overpayment guard under REAL cross-process
 * row-lock contention — not a simulation running inside one PHPUnit
 * process/transaction. See that test class's docblock for the full design
 * rationale (why a genuine two-connection race can't be built the way
 * IdempotencyTest's race-sim trick does, and why a third "lock holder"
 * process is needed to make the contention window deterministic instead of
 * a coin-flip on process-scheduling luck).
 *
 * Two roles, chosen by the first argument:
 *
 *  - `hold`: opens a raw transaction, takes lockForUpdate() on the invoice
 *    row, writes a marker file the INSTANT the lock is acquired (so the
 *    calling test knows precisely when it's safe to start the `pay`
 *    workers — no blind sleep/guessing), holds the lock for --hold-ms
 *    milliseconds, then rolls back (releasing it, changing nothing). Never
 *    calls InvoicePaymentService — its only job is to widen the contention
 *    window deterministically so both `pay` workers are guaranteed to
 *    genuinely block on the SAME real InnoDB row lock, not just get lucky
 *    (or unlucky) with process-startup timing.
 *
 *  - `pay`: calls the REAL, completely unmodified
 *    InvoicePaymentService::recordPayment() exactly as InvoiceController
 *    does for a real payment — no artificial delay, no wrapping transaction
 *    of its own. (IdempotencyService::execute() explicitly forbids being
 *    called from inside an already-open transaction — see its class
 *    docblock, "it owns the transaction boundary" — so this role must NOT
 *    pre-open one; the `hold` role above is what widens the window instead.)
 *
 * Refuses to run outside local/testing — this command has no reason to ever
 * be reachable in production.
 */
class ConcurrencyProbeInvoicePayment extends Command
{
    protected $signature = 'sparkbooks:concurrency-probe-invoice-payment
        {mode : hold|pay}
        {companyId : Company owning the invoice}
        {invoiceId : Invoice row being contended for}
        {--hold-ms=700 : (hold mode) milliseconds to hold the row lock before releasing it}
        {--marker= : (hold mode) file path to create the instant the lock is acquired}
        {--amount= : (pay mode) payment amount, minor units}
        {--bank-account-id= : (pay mode) bank account receiving the payment}
        {--payment-date= : (pay mode) Y-m-d}
        {--idempotency-key= : (pay mode) UUID}';

    protected $description = 'TEST-SUPPORT ONLY: real cross-process row-lock contention probe for InvoicePaymentConcurrencyTest (spec §12.6). Not a product feature.';

    public function handle(CompanyContext $context): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is test-support only and refuses to run outside local/testing.');

            return self::FAILURE;
        }

        $company = Company::query()->findOrFail((int) $this->argument('companyId'));
        $invoiceId = (int) $this->argument('invoiceId');

        return $context->runFor($company, fn () => $this->argument('mode') === 'hold'
            ? $this->hold($invoiceId)
            : $this->pay($invoiceId));
    }

    private function hold(int $invoiceId): int
    {
        $holdMs = (int) $this->option('hold-ms');
        $marker = (string) $this->option('marker');

        DB::beginTransaction();
        DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
        $acquiredAt = microtime(true);
        file_put_contents($marker, (string) $acquiredAt);

        usleep($holdMs * 1000);

        DB::rollBack();
        $releasedAt = microtime(true);

        $this->line(json_encode([
            'role' => 'hold',
            'acquired_at' => $acquiredAt,
            'released_at' => $releasedAt,
        ]));

        return self::SUCCESS;
    }

    private function pay(int $invoiceId): int
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $data = [
            'amount' => (int) $this->option('amount'),
            'bank_account_id' => (int) $this->option('bank-account-id'),
            'payment_date' => (string) $this->option('payment-date'),
        ];
        $key = (string) $this->option('idempotency-key');

        $startedAt = microtime(true);
        $service = app(InvoicePaymentService::class);

        try {
            $payment = $service->recordPayment($key, $invoice, $data);
            $finishedAt = microtime(true);
            $this->line(json_encode([
                'role' => 'pay',
                'outcome' => 'ok',
                'payment_id' => $payment->id,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]));
        } catch (Throwable $e) {
            $finishedAt = microtime(true);
            $this->line(json_encode([
                'role' => 'pay',
                'outcome' => 'rejected',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]));
        }

        return self::SUCCESS;
    }
}
