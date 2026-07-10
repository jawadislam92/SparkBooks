<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\BillStatus;
use App\Exceptions\Documents\BillNotPayable;
use App\Exceptions\Documents\BillPaymentBankAccountIneligible;
use App\Exceptions\Documents\BillPaymentExceedsBalance;
use App\Exceptions\Documents\InvalidBillPaymentAmount;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment against a received/partially-paid bill: the AP-side
 * mirror of InvoicePaymentService::recordPayment (P3-T4) — posting rule P7
 * (spec §6): Dr 2000 Accounts Payable (amount, contactId = bill.contact_id,
 * since AP is a control account) / Cr the paying bank/cash account's linked
 * ledger account (amount). Bills are base-currency-only in this phase (Phase
 * 4 brief) — no P7fx, no base_amount/base_ar_relief/fx_rate the way
 * InvoicePayment carries them; `amount` is the whole story.
 *
 * Money-critical + concurrency-critical (spec §9), same reasoning as
 * InvoicePaymentService's own docblock: two concurrent payment requests
 * against the same bill must never both succeed if together they'd overpay
 * it. The overpayment guard runs against a lockForUpdate() READ of the bill
 * row taken INSIDE this method's own transaction — a second concurrent
 * caller blocks on that row lock until the first commits, then re-reads the
 * now-reduced balance/updated status and is correctly rejected.
 *
 * Shape mirrors InvoicePaymentService::recordPayment/InvoiceService::send/
 * BillService::receive: IdempotencyService::execute -> DB::transaction ->
 * locked read -> guards -> BillPayment row -> LedgerService::post -> bill
 * row update -> AuditLogger. Void (posting-rule reversal, the payment-side
 * counterpart of BillService's eventual void()) is Task 6, not built here —
 * bill_payments.status already carries a 'posted'|'void' enum (Task 1) so
 * that later work has somewhere to land.
 */
class BillPaymentService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /** @param array{amount:int, bank_account_id:int, payment_date:string, memo?:?string} $data */
    public function record(string $idempotencyKey, Bill $bill, array $data): BillPayment
    {
        $result = $this->idempotency->execute($idempotencyKey, 'bill_payment.recorded', function () use ($bill, $data) {
            return DB::transaction(function () use ($bill, $data) {
                // Row-lock + re-read FRESH state: $bill may have been
                // resolved by the controller moments before this closure
                // runs (and on an idempotency replay this closure doesn't
                // execute at all), so the overpayment guard below MUST see
                // the CURRENT balance/status — and the lock itself is what
                // serializes two concurrent payments against the same bill
                // (the second blocks here until the first commits, then
                // sees the updated balance). Mirrors InvoicePaymentService::
                // recordPayment exactly.
                $locked = Bill::query()->where('id', $bill->id)->lockForUpdate()->firstOrFail();

                $amount = (int) $data['amount'];
                $this->guard($locked, $amount);

                $bankAccount = $this->resolveBankAccount((int) $data['bank_account_id']);

                $payment = BillPayment::query()->create([
                    'bill_id' => $locked->id,
                    'payment_date' => $data['payment_date'],
                    'amount' => $amount,
                    'bank_account_id' => $bankAccount->id,
                    'status' => 'posted',
                    'memo' => $data['memo'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['payment_date']),
                    memo: $locked->reference !== null ? "Payment for bill {$locked->reference}" : "Payment for bill #{$locked->id}",
                    sourceType: 'bill_payment',
                    sourceId: $payment->id,
                    lines: $this->p7Lines($locked, $bankAccount, $amount),
                ));

                $newAmountPaid = $locked->amount_paid + $amount;
                $newBalance = $locked->total - $newAmountPaid;

                // The bill ROW keeps changing after receive() (amount_paid,
                // balance, status) — only the LINES are frozen once received
                // (BillLine::booted()) — mirrors InvoicePaymentService's own
                // identical reasoning for Invoice.
                $locked->forceFill([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'status' => ($newBalance === 0 ? BillStatus::Paid : BillStatus::PartiallyPaid)->value,
                ])->save();

                $this->audit->log('bill_payment.recorded', $payment, after: [
                    'bill_id' => $locked->id,
                    'amount' => $amount,
                    'entry_id' => $entry->id,
                ]);

                return ['id' => $payment->id];
            });
        });

        return BillPayment::query()->findOrFail($result['id']);
    }

    /**
     * Guards enforced on the LOCKED row (spec §9): status eligible, amount
     * positive, amount doesn't exceed the outstanding balance. This is the
     * ONLY place overpayment is checked — two concurrent payments against
     * the same bill race on the lockForUpdate() read above, so whichever
     * commits second re-reads the now-reduced balance here and is correctly
     * rejected, even if it had already passed HTTP-layer validation against
     * a stale balance. Mirrors InvoicePaymentService::guard exactly, minus
     * the base_amount leg (bills have no FX in this phase).
     */
    private function guard(Bill $bill, int $amount): void
    {
        if (! $bill->status->canReceivePayment()) {
            throw new BillNotPayable('Only a received or partially paid bill can receive a payment.');
        }

        // Defense-in-depth: StoreBillPaymentRequest already enforces
        // amount >= 1 at the HTTP layer; this re-check protects any other
        // caller.
        if ($amount < 1) {
            throw new InvalidBillPaymentAmount('Payment amount must be at least 1.');
        }

        if ($amount > $bill->balance) {
            throw new BillPaymentExceedsBalance(
                "Payment exceeds the outstanding balance of {$this->formatMinor($bill->balance)}."
            );
        }
    }

    /**
     * Defense-in-depth re-resolution of the bank account: StoreBillPaymentRequest
     * already validates tenant-ownership/archived at the HTTP layer, but this
     * service is the actual money-moving boundary, so it re-checks rather
     * than trusting the caller. BankAccount's BelongsToCompany global scope
     * makes a foreign-company id indistinguishable from a nonexistent one —
     * both come back null here and are rejected the same way. Mirrors
     * InvoicePaymentService::resolveBankAccount exactly.
     */
    private function resolveBankAccount(int $bankAccountId): BankAccount
    {
        $bankAccount = BankAccount::query()->find($bankAccountId);

        if ($bankAccount === null || $bankAccount->is_archived) {
            throw new BillPaymentBankAccountIneligible('The selected bank account is unavailable for this payment.');
        }

        return $bankAccount;
    }

    /**
     * Posting rule P7 (spec §6): Dr 2000 Accounts Payable for the amount,
     * WITH contact_id (LedgerService requires a contact on every
     * control-account line — 2000's Payable subtype makes this mandatory
     * regardless, but it's passed explicitly here for clarity, mirroring
     * BillService::p6Lines' own AP credit line); Cr the bank account's
     * linked ledger account for the same amount. Two lines, always — no FX
     * plug (bills are base-currency-only in this phase, unlike
     * InvoicePaymentService::paymentLines' up-to-three-line P2fx shape).
     *
     * @return list<LineDraft>
     */
    private function p7Lines(Bill $bill, BankAccount $bankAccount, int $amount): array
    {
        $apAccount = Account::query()->where('code', '2000')->firstOrFail();

        return [
            new LineDraft(accountId: $apAccount->id, debit: $amount, credit: 0, contactId: $bill->contact_id),
            new LineDraft(accountId: $bankAccount->ledger_account_id, debit: 0, credit: $amount),
        ];
    }

    /**
     * Integer-only minor-to-decimal-string formatting for the overpayment
     * error message — never float math. Mirrors
     * InvoicePaymentService::formatMinor/TrialBalanceController::minorToDecimalString.
     */
    private function formatMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
