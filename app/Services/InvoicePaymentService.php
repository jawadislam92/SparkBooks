<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\Invoicing\InvalidPaymentAmount;
use App\Exceptions\Invoicing\InvoiceNotPayable;
use App\Exceptions\Invoicing\PaymentAlreadyVoided;
use App\Exceptions\Invoicing\PaymentBankAccountIneligible;
use App\Exceptions\Invoicing\PaymentExceedsBalance;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment against a sent/partially-paid invoice: posting rules P2
 * (full payment) / P3 (partial payment) — spec §6 — Dr the receiving
 * bank/cash account's linked ledger account; Cr 1100 AR (with the invoice's
 * contact_id, since AR is a control account). P2 and P3 share one posting
 * shape — only the amount (the whole balance vs. part of it) differs, which
 * is why one method (paymentLines()) covers both.
 *
 * Money-critical + concurrency-critical (spec §9): two concurrent payment
 * requests against the same invoice must never both succeed if together
 * they'd overpay it. The overpayment guard runs against a lockForUpdate()
 * READ of the invoice row taken INSIDE this method's own transaction — the
 * second concurrent caller blocks on that row lock until the first commits,
 * then re-reads the now-reduced balance/updated status and is correctly
 * rejected (see InvoicePaymentTest's concurrency section for how this is
 * verified under RefreshDatabase).
 *
 * Domestic-only (Task 8 adds FX payments): base_amount always equals amount,
 * fx_rate is always null.
 *
 * Shape mirrors InvoiceService::send()/TransferService::transfer():
 * IdempotencyService::execute -> DB::transaction -> locked read -> guards ->
 * document mutation -> LedgerService::post -> invoice row update ->
 * AuditLogger.
 */
class InvoicePaymentService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /** @param array{amount:int, bank_account_id:int, payment_date:string, memo?:?string} $data */
    public function recordPayment(string $idempotencyKey, Invoice $invoice, array $data): InvoicePayment
    {
        $result = $this->idempotency->execute($idempotencyKey, 'invoice.payment_recorded', function () use ($invoice, $data) {
            return DB::transaction(function () use ($invoice, $data) {
                // Row-lock + re-read FRESH state: $invoice may have been
                // resolved by the controller moments before this closure
                // runs (and on an idempotency replay this closure doesn't
                // execute at all), so the overpayment guard below MUST see
                // the CURRENT balance/status — and the lock itself is what
                // serializes two concurrent payments against the same
                // invoice (the second blocks here until the first commits,
                // then sees the updated balance).
                $locked = Invoice::query()->where('id', $invoice->id)->lockForUpdate()->firstOrFail();

                $amount = (int) $data['amount'];
                $this->guard($locked, $amount);

                $bankAccount = $this->resolveBankAccount((int) $data['bank_account_id']);

                $payment = InvoicePayment::query()->create([
                    'invoice_id' => $locked->id,
                    'payment_date' => $data['payment_date'],
                    'amount' => $amount,
                    // Domestic only (Task 8 adds FX payments) — base always
                    // mirrors the document-currency amount, fx_rate stays null.
                    'base_amount' => $amount,
                    'bank_account_id' => $bankAccount->id,
                    'fx_rate' => null,
                    'status' => 'posted',
                    'memo' => $data['memo'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['payment_date']),
                    memo: "Payment for {$locked->number}",
                    sourceType: 'invoice_payment',
                    sourceId: $payment->id,
                    lines: $this->paymentLines($locked, $bankAccount, $amount),
                ));

                $newAmountPaid = $locked->amount_paid + $amount;
                $newBalance = $locked->total - $newAmountPaid;

                // The invoice ROW keeps changing after send() (amount_paid,
                // balance, status) — only the LINES are frozen once posted
                // (InvoiceLine::booted()), so this update is safe here too.
                $locked->forceFill([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'status' => ($newBalance === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid)->value,
                ])->save();

                $this->audit->log('invoice.payment_recorded', $payment, after: [
                    'invoice_id' => $locked->id,
                    'amount' => $amount,
                    'entry_id' => $entry->id,
                ]);

                return ['id' => $payment->id];
            });
        });

        return InvoicePayment::query()->findOrFail($result['id']);
    }

    /**
     * Reverses a posted payment: posting rule P9's payment-side counterpart —
     * reverses the payment's own P2/P3 entry (Cr bank / Dr AR, the mirror of
     * Dr bank / Cr AR), marks the payment 'void', and recomputes the invoice
     * back toward sent/partially_paid (spec §6: voiding a payment is a
     * conscious per-payment action, never an automatic side effect of voiding
     * the invoice — InvoiceService::void() REFUSES while any payment here is
     * still 'posted').
     *
     * Shape mirrors recordPayment(): IdempotencyService::execute ->
     * DB::transaction -> locked read -> guard -> LedgerService::reverse ->
     * document mutation -> AuditLogger. The INVOICE row is locked (not just
     * the payment) because amount_paid/balance/status on the invoice are
     * recomputed in the same transaction, and locking the invoice is what
     * already serializes every payment-mutating action against it
     * (recordPayment does the same) — a concurrent second voidPayment (or a
     * concurrent recordPayment) blocks here until this transaction commits.
     */
    public function voidPayment(string $idempotencyKey, InvoicePayment $payment): InvoicePayment
    {
        $result = $this->idempotency->execute($idempotencyKey, 'invoice.payment_voided', function () use ($payment) {
            return DB::transaction(function () use ($payment) {
                $invoice = Invoice::query()->where('id', $payment->invoice_id)->lockForUpdate()->firstOrFail();

                // Re-read the payment fresh under lock — must see the CURRENT
                // status, not whatever the controller resolved moments ago
                // (and on an idempotency replay this closure doesn't run at
                // all, so there's no stale-row risk there either).
                $locked = InvoicePayment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== 'posted') {
                    throw new PaymentAlreadyVoided('This payment has already been reversed.');
                }

                $entry = JournalEntry::query()
                    ->where('source_type', 'invoice_payment')
                    ->where('source_id', $locked->id)
                    ->where('entry_role', EntryRole::Primary->value)
                    ->firstOrFail();

                $reversal = $this->ledger->reverse(
                    $entry,
                    CarbonImmutable::today(),
                    "Reversed payment for {$invoice->number}"
                );

                $locked->forceFill(['status' => 'void'])->save();

                $newAmountPaid = $invoice->amount_paid - $locked->amount;
                $newBalance = $invoice->total - $newAmountPaid;

                // Back toward sent/partially_paid, never paid — an invoice
                // with a reversed payment is by definition no longer fully
                // paid (see class docblock: only LINES are frozen post-send,
                // this row keeps changing).
                $invoice->forceFill([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'status' => ($newAmountPaid === 0 ? InvoiceStatus::Sent : InvoiceStatus::PartiallyPaid)->value,
                ])->save();

                $this->audit->log('invoice.payment_voided', $locked, after: [
                    'invoice_id' => $invoice->id,
                    'reversal_entry_id' => $reversal->id,
                ]);

                return ['id' => $locked->id];
            });
        });

        return InvoicePayment::query()->findOrFail($result['id']);
    }

    /**
     * Guards enforced on the LOCKED row (spec §9): status eligible, amount
     * positive, amount doesn't exceed the outstanding balance. This is the
     * ONLY place overpayment is checked — two concurrent payments against the
     * same invoice race on the lockForUpdate() read above, so whichever
     * commits second re-reads the now-reduced balance here and is correctly
     * rejected, even if it had already passed HTTP-layer validation against a
     * stale balance.
     */
    private function guard(Invoice $invoice, int $amount): void
    {
        if (! $invoice->status->canReceivePayment()) {
            throw new InvoiceNotPayable('Only a sent or partially paid invoice can receive a payment.');
        }

        // Defense-in-depth: StorePaymentRequest already enforces amount >= 1
        // at the HTTP layer; this re-check protects any other caller.
        if ($amount < 1) {
            throw new InvalidPaymentAmount('Payment amount must be at least 1.');
        }

        if ($amount > $invoice->balance) {
            throw new PaymentExceedsBalance(
                "Payment exceeds the outstanding balance of {$this->formatMinor($invoice->balance)}."
            );
        }
    }

    /**
     * Defense-in-depth re-resolution of the bank account: StorePaymentRequest
     * already validates tenant-ownership/archived at the HTTP layer, but this
     * service is the actual money-moving boundary, so it re-checks rather
     * than trusting the caller. BankAccount's BelongsToCompany global scope
     * makes a foreign-company id indistinguishable from a nonexistent one —
     * both come back null here and are rejected the same way.
     */
    private function resolveBankAccount(int $bankAccountId): BankAccount
    {
        $bankAccount = BankAccount::query()->find($bankAccountId);

        if ($bankAccount === null || $bankAccount->is_archived) {
            throw new PaymentBankAccountIneligible('The selected bank account is unavailable for this payment.');
        }

        return $bankAccount;
    }

    /**
     * Posting rules P2 (full)/P3 (partial) — spec §6: Dr the bank account's
     * linked ledger account for the payment amount; Cr 1100 AR for the same
     * amount, WITH contact_id (LedgerService requires it on every
     * control-account line). Identical shape for a full or partial payment —
     * only the amount differs.
     *
     * @return list<LineDraft>
     */
    private function paymentLines(Invoice $invoice, BankAccount $bankAccount, int $amount): array
    {
        $arAccount = Account::query()->where('code', '1100')->firstOrFail();

        return [
            new LineDraft(accountId: $bankAccount->ledger_account_id, debit: $amount, credit: 0),
            new LineDraft(accountId: $arAccount->id, debit: 0, credit: $amount, contactId: $invoice->contact_id),
        ];
    }

    /**
     * Integer-only minor-to-decimal-string formatting for the overpayment
     * error message — never float math, mirrors
     * TrialBalanceController::minorToDecimalString.
     */
    private function formatMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
