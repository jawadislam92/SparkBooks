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
use App\Support\FxConverter;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment against a sent/partially-paid invoice: posting rules P2
 * (full payment) / P3 (partial payment) — spec §6 — Dr the receiving
 * bank/cash account's linked ledger account; Cr 1100 AR (with the invoice's
 * contact_id, since AR is a control account); extended for a foreign-currency
 * invoice by posting rule P2fx (spec §17.1, Phase 3 Task 8), which adds a
 * realized FX gain/loss plug line. All three share one posting shape (Dr
 * bank / Cr AR [/ Cr or Dr FX gain/loss]) — only the amounts differ, which is
 * why one method (paymentLines()) covers all of them; a domestic payment is
 * just an FX payment at fx_rate 1.0 where the plug always comes out to 0 (see
 * below), not a structurally different code path (mirrors InvoiceService's
 * own P1/P1fx unification).
 *
 * **P2fx (spec §17.1):** the request carries `amount` (document currency —
 * what portion of the invoice this pays, same as always) and `base_amount`
 * (the ACTUAL base/PKR amount the bank credited, user-entered off the bank
 * statement — never rate-derived, so it can differ from amount x rate by
 * spread/fees and still let §7.9 reconciliation hit a difference of exactly
 * 0). The AR credit is a SEPARATE number, `base_ar_relief` — relief at the
 * INVOICE's own fx_rate, not at whatever rate this particular bank credit
 * implies:
 *   - Partial payment (doesn't bring the doc balance to 0): base_ar_relief =
 *     FxConverter::toBase(amount, invoice.fx_rate) — round-half-up at the
 *     invoice rate.
 *   - FINAL payment (brings the doc balance to exactly 0): base_ar_relief =
 *     the entire REMAINING base AR for this invoice (base_total minus every
 *     prior non-void payment's base_ar_relief), NOT round(rate x amount) —
 *     this is the "settlement rounding" rule: successive partials each round
 *     independently and can leave a residue of a paisa or two, and the final
 *     payment absorbs exactly that residue so base AR always zeroes out
 *     exactly. See InvoiceFxPaymentTest's settlement-rounding-property test
 *     for a worked case where naive per-payment rounding would leave a
 *     phantom balance and this rule doesn't.
 *   - The FX plug: diff = base_amount (actual bank debit) - base_ar_relief
 *     (AR credit at invoice rate). diff > 0 -> Cr 4950 FX Gain; diff < 0 ->
 *     Dr 5950 FX Loss; diff === 0 -> no third line at all.
 * For a domestic invoice (fx_rate exactly 1.0), FxConverter::toBase(amount,
 * '1') is the identity, so base_ar_relief always equals amount regardless of
 * branch — but base_amount is a genuinely separate INPUT (not derived), and
 * there is no such thing as an FX gain/loss on a same-currency invoice, so
 * resolveBaseAmount() below still forces base_amount = amount for a domestic
 * invoice rather than trusting a stray value from the caller (mirrors
 * InvoiceService::resolveFxRate ignoring a stray fx_rate on a domestic send)
 * — this keeps diff exactly 0 and Task 4's two-line entry shape unchanged by
 * construction, not by coincidence.
 *
 * Money-critical + concurrency-critical (spec §9): two concurrent payment
 * requests against the same invoice must never both succeed if together
 * they'd overpay it. The overpayment guard runs against a lockForUpdate()
 * READ of the invoice row taken INSIDE this method's own transaction — the
 * second concurrent caller blocks on that row lock until the first commits,
 * then re-reads the now-reduced balance/updated status and is correctly
 * rejected (see InvoicePaymentTest's concurrency section for how this is
 * verified under RefreshDatabase). The same lock is what makes the
 * "remaining base AR" sum used by a FINAL payment race-safe: a concurrent
 * payment against the same invoice blocks on this row until the first
 * commits, so the SUM(base_ar_relief) a final payment reads can never miss a
 * payment that's still in flight.
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

    /** @param array{amount:int, base_amount?:int|string|null, bank_account_id:int, payment_date:string, memo?:?string} $data */
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
                $baseAmount = $this->resolveBaseAmount($locked, $amount, $data);
                $this->guard($locked, $amount, $baseAmount);

                $bankAccount = $this->resolveBankAccount((int) $data['bank_account_id']);

                // "Final" = this payment brings the DOCUMENT-currency balance
                // to exactly 0 — checked against the LOCKED row's balance
                // (pre-mutation), which the guard above already established
                // $amount cannot exceed. This is what selects the settlement-
                // rounding branch below (spec §17.1).
                $isFinalPayment = $amount === $locked->balance;
                $baseArRelief = $this->resolveBaseArRelief($locked, $amount, $isFinalPayment);

                $payment = InvoicePayment::query()->create([
                    'invoice_id' => $locked->id,
                    'payment_date' => $data['payment_date'],
                    'amount' => $amount,
                    'base_amount' => $baseAmount,
                    'base_ar_relief' => $baseArRelief,
                    'bank_account_id' => $bankAccount->id,
                    // Payment-date implied rate, for display only (spec
                    // §17.1: "the implied rate is shown [to the user],
                    // not typed") — never fed back into any posting
                    // calculation, which always uses the INVOICE's own
                    // fx_rate (via base_ar_relief), not this payment's rate.
                    // null for a domestic invoice, mirroring Task 4.
                    'fx_rate' => $this->impliedRate($locked, $amount, $baseAmount),
                    'status' => 'posted',
                    'memo' => $data['memo'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['payment_date']),
                    memo: "Payment for {$locked->number}",
                    sourceType: 'invoice_payment',
                    sourceId: $payment->id,
                    lines: $this->paymentLines($locked, $bankAccount, $baseAmount, $baseArRelief),
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
     * positive, amount doesn't exceed the outstanding balance, base_amount
     * positive (Task 8). This is the ONLY place overpayment is checked — two
     * concurrent payments against the same invoice race on the
     * lockForUpdate() read above, so whichever commits second re-reads the
     * now-reduced balance here and is correctly rejected, even if it had
     * already passed HTTP-layer validation against a stale balance.
     * Overpayment is checked in DOCUMENT currency only (spec §17.1: "still
     * checked in document currency") — base_amount never participates in
     * this comparison, since it's independently what the bank happened to
     * credit, not a second measure of how much of the invoice this pays.
     */
    private function guard(Invoice $invoice, int $amount, int $baseAmount): void
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

        // Defense-in-depth: StorePaymentRequest already enforces base_amount
        // >= 1 (required outright for a foreign-currency invoice) at the HTTP
        // layer; this re-check protects any other caller. For a domestic
        // invoice this can never fire — resolveBaseAmount() already forces
        // base_amount = amount, which the check above already proved >= 1.
        if ($baseAmount < 1) {
            throw new InvalidPaymentAmount('Base amount must be at least 1.');
        }
    }

    /**
     * The bank DEBIT (base/PKR minor units) — for a domestic invoice this is
     * ALWAYS forced to equal amount (Task 4 behavior, unchanged): a same-
     * currency payment has no independent "actual base received" to enter,
     * so any base_amount supplied by a caller for a domestic invoice is
     * simply ignored, exactly as InvoiceService::resolveFxRate ignores a
     * stray fx_rate on a domestic send. For a foreign-currency invoice,
     * base_amount is a genuine, independent user-entered input (spec §17.1:
     * "the actual base amount the bank credited") — taken from $data as-is.
     *
     * @param  array{amount:int, base_amount?:int|string|null, bank_account_id:int, payment_date:string, memo?:?string}  $data
     */
    private function resolveBaseAmount(Invoice $invoice, int $amount, array $data): int
    {
        if ($this->isDomestic($invoice)) {
            return $amount;
        }

        return (int) ($data['base_amount'] ?? 0);
    }

    /**
     * The AR credit (base/PKR minor units) — posting rule P2fx's settlement
     * rounding rule (spec §17.1). A partial payment relieves AR at the
     * INVOICE's own fx_rate; the payment that brings the document balance to
     * exactly 0 instead relieves whatever base AR is actually still
     * outstanding for this invoice, so a run of independently-rounded
     * partials can never leave a phantom paisa of AR behind. For a domestic
     * invoice (fx_rate exactly 1.0) FxConverter::toBase is the identity, so
     * this always resolves to the same value Task 4 posted (== amount) on
     * BOTH branches — no special case needed, same reasoning as
     * InvoiceService::p1Lines.
     */
    private function resolveBaseArRelief(Invoice $invoice, int $amount, bool $isFinalPayment): int
    {
        if (! $isFinalPayment) {
            return FxConverter::toBase($amount, (string) $invoice->fx_rate);
        }

        // Sum of every prior LIVE (non-void) payment's own base_ar_relief —
        // not a re-derivation from their amounts/rates — so this always
        // matches what was actually posted to the ledger, rounding residue
        // and all. The invoice row lock held by the caller's transaction
        // serializes this against any concurrent payment on the same
        // invoice, so this SUM can never miss one still in flight.
        $priorBaseArRelief = (int) InvoicePayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', '!=', 'void')
            ->sum('base_ar_relief');

        return $invoice->base_total - $priorBaseArRelief;
    }

    /**
     * The payment-date implied rate (base_amount / amount), shown to the
     * user per spec §17.1 ("the implied rate is displayed, not typed") —
     * display-only, never consulted by any posting calculation above (those
     * always use the INVOICE's own fx_rate). Null for a domestic invoice,
     * mirroring Task 4's InvoicePayment.fx_rate contract (migration: "only
     * meaningful for a foreign-currency invoice"). Scaled to fx_rate's own
     * DECIMAL(16,8) precision via bcdiv, never float division.
     */
    private function impliedRate(Invoice $invoice, int $amount, int $baseAmount): ?string
    {
        if ($this->isDomestic($invoice)) {
            return null;
        }

        return bcdiv((string) $baseAmount, (string) $amount, 8);
    }

    /**
     * A domestic invoice's fx_rate is fixed at exactly 1.0 by
     * InvoiceService::resolveFxRate at send time and never changes — an
     * invoice can only receive payments once sent, so this is always a
     * reliable domestic/foreign test without needing the company's
     * base_currency in scope here.
     */
    private function isDomestic(Invoice $invoice): bool
    {
        return bccomp((string) $invoice->fx_rate, '1', 8) === 0;
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
     * Posting rules P2 (full)/P3 (partial)/P2fx (spec §6/§17.1): Dr the bank
     * account's linked ledger account for the actual base amount received;
     * Cr 1100 AR for the base AR relief, WITH contact_id (LedgerService
     * requires it on every control-account line); plus a third line for
     * whatever's left over — Cr 4950 FX Gain if the bank received MORE base
     * currency than the AR relief, Dr 5950 FX Loss if it received LESS. No
     * third line at all when the two exactly match (always true for a
     * domestic payment, by construction — see resolveBaseAmount()/
     * resolveBaseArRelief() above), which is exactly Task 4's original
     * two-line shape. The entry balances by construction on every branch:
     * diff > 0 => debit(bank) = baseAmount = baseArRelief + diff =
     * credit(AR) + credit(gain); diff < 0 => debit(bank) + debit(loss) =
     * baseAmount + (baseArRelief - baseAmount) = baseArRelief = credit(AR).
     *
     * @return list<LineDraft>
     */
    private function paymentLines(Invoice $invoice, BankAccount $bankAccount, int $baseAmount, int $baseArRelief): array
    {
        $arAccount = Account::query()->where('code', '1100')->firstOrFail();

        $lines = [
            new LineDraft(accountId: $bankAccount->ledger_account_id, debit: $baseAmount, credit: 0),
            new LineDraft(accountId: $arAccount->id, debit: 0, credit: $baseArRelief, contactId: $invoice->contact_id),
        ];

        $diff = $baseAmount - $baseArRelief;

        if ($diff > 0) {
            $fxGain = Account::query()->where('code', '4950')->firstOrFail();
            $lines[] = new LineDraft(accountId: $fxGain->id, debit: 0, credit: $diff);
        } elseif ($diff < 0) {
            $fxLoss = Account::query()->where('code', '5950')->firstOrFail();
            $lines[] = new LineDraft(accountId: $fxLoss->id, debit: -$diff, credit: 0);
        }

        return $lines;
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
