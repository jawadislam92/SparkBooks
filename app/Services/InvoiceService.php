<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Enums\InvoiceStatus;
use App\Exceptions\Invoicing\InvoiceContactNotEligible;
use App\Exceptions\Invoicing\InvoiceFxRateInvalid;
use App\Exceptions\Invoicing\InvoiceHasLivePayments;
use App\Exceptions\Invoicing\InvoiceHasNoLines;
use App\Exceptions\Invoicing\InvoiceHasNoValue;
use App\Exceptions\Invoicing\InvoiceNotDraft;
use App\Exceptions\Invoicing\InvoiceNotVoidable;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\FxConverter;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sends a draft invoice: assigns its document number, freezes each line's
 * tax rate, converts document-currency totals to base-currency mirrors, posts
 * posting rule P1/P1fx (Dr 1100 AR / Cr income per line / Cr 2100 Tax
 * Payable, all in BASE minor units) via LedgerService, and flips the invoice
 * to Sent — the first ledger-posting invoice action (Phase 3 Task 3, extended
 * for FX in Task 7). Every conversion goes through FxConverter component-wise
 * (spec §17.1's rounding rule): for a domestic invoice (currency_code equals
 * the company's base currency) fx_rate is fixed at exactly 1.0, and
 * FxConverter::toBase(amount, '1') always returns `amount` unchanged (no
 * rounding is possible when multiplying by 1) — so base_* mirrors the
 * document totals exactly, with no special-cased domestic branch needed in
 * the conversion step itself. This mirrors spec §3's own instruction: "no
 * code may assume fx = 1" — the domestic case is just FX with rate 1, not a
 * structurally different code path.
 *
 * Shape mirrors ManualJournalService/TransferService/OpeningBalanceService:
 * IdempotencyService::execute -> DB::transaction -> document mutation ->
 * LedgerService::post -> AuditLogger.
 */
class InvoiceService
{
    public function __construct(
        private LedgerService $ledger,
        private DocumentNumberService $numbers,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
        private CompanyContext $context,
    ) {}

    /**
     * $fxRate is the SEND-time rate for a foreign-currency invoice (manually
     * entered/editable per spec §17.1 — captured at send, not before), as a
     * decimal string straight from the request body; null/absent for a
     * domestic invoice, where it's ignored (fx_rate is always fixed at 1.0
     * regardless of what's passed here — see resolveFxRate()).
     */
    public function send(string $idempotencyKey, Invoice $draft, ?string $fxRate = null): Invoice
    {
        $result = $this->idempotency->execute($idempotencyKey, 'invoice.sent', function () use ($draft, $fxRate) {
            return DB::transaction(function () use ($draft, $fxRate) {
                // Re-read fresh inside the transaction: $draft may have been
                // resolved by the controller moments before this closure runs
                // (and on an idempotency replay this closure doesn't execute
                // at all), so this guarantees the guards below always see the
                // CURRENT row/lines/contact.
                $invoice = Invoice::query()
                    ->with(['contact', 'lines' => fn ($q) => $q->orderBy('id')])
                    ->findOrFail($draft->id);

                $this->guard($invoice);

                // Resolved BEFORE freezeLines() mutates any line rows: a
                // request missing a required fx_rate should fail cleanly with
                // the draft left completely untouched, not after lines have
                // already been rewritten.
                $resolvedFxRate = $this->resolveFxRate($invoice, $fxRate);

                // Freeze each line's tax rate + recompute totals from the
                // frozen rate WHILE the invoice is still draft —
                // InvoiceLine::booted()'s freeze guard rejects line writes
                // once status leaves draft, so this MUST happen before the
                // status flip below (see T1 carry note / PROJECT_MAP §6).
                $computed = $this->freezeLines($invoice);

                // Degenerate case: every line is $0 and there's no tax, so
                // the computed total is 0. There is nothing to post, and a
                // zero AR debit would violate LedgerService's "each line
                // one-sided" rule — reject BEFORE burning a document number.
                // Checked here (not in guard()) because it needs the freshly
                // computed total; freezeLines() has no other side effect that
                // a rejection here would leave stranded (the invoice stays
                // draft, so its just-refrozen lines remain freely editable).
                // A zero DOCUMENT total also means a zero BASE total (any
                // component converts to 0 regardless of rate), so checking
                // the document total alone is sufficient here.
                if ($computed['total'] <= 0) {
                    throw new InvoiceHasNoValue('An invoice must have a total greater than zero to be sent.');
                }

                // Component-wise base conversion (spec §17.1 P1fx): each
                // line's subtotal and tax convert independently and the
                // results are summed — never a single round(docTotal x rate)
                // shot (see FxConverter's own docblock for why those two
                // paths can disagree). base_total = base_subtotal +
                // base_tax_total, so the P1fx AR debit built from it always
                // equals Σ(base income credits) + base tax credit by
                // construction.
                $base = $this->convertToBase($computed, $resolvedFxRate);

                $number = $this->numbers->next('INV', 'INV');
                $issueDate = $invoice->issue_date?->toDateString() ?? CarbonImmutable::today()->toDateString();

                // The invoice ROW keeps changing after this point (status,
                // sent_at, ...) — only the LINE ITEMS are frozen, so this
                // update is safe even though status is about to become 'sent'.
                $invoice->forceFill([
                    'number' => $number,
                    'status' => InvoiceStatus::Sent->value,
                    'sent_at' => now(),
                    'issue_date' => $issueDate,
                    'fx_rate' => $resolvedFxRate,
                    'subtotal' => $computed['subtotal'],
                    'tax_total' => $computed['tax_total'],
                    'total' => $computed['total'],
                    'amount_paid' => 0,
                    'balance' => $computed['total'],
                    'base_subtotal' => $base['subtotal'],
                    'base_tax_total' => $base['tax_total'],
                    'base_total' => $base['total'],
                ])->save();

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($issueDate),
                    memo: "Invoice {$number}",
                    sourceType: 'invoice',
                    sourceId: $invoice->id,
                    lines: $this->p1Lines($invoice, $base),
                ));

                $this->audit->log('invoice.sent', $invoice, after: [
                    'number' => $number,
                    'entry_id' => $entry->id,
                ]);

                return ['id' => $invoice->id];
            });
        });

        return Invoice::query()->findOrFail($result['id']);
    }

    /**
     * Domestic (currency_code === company base_currency): fx_rate is always
     * fixed at exactly '1', regardless of anything supplied in $fxRate — a
     * domestic invoice never needs one and never depends on request input for
     * it (spec §17.1: "a domestic invoice still fx_rate 1.0").
     *
     * Foreign: $fxRate must be a valid conversion rate. "Valid fx_rate shape"
     * is defined in exactly ONE place — FxConverter's own guard — which we
     * probe here with a nominal conversion so EVERY shape violation
     * (missing/empty, non-numeric, <= 0, OR more than 8 decimal places)
     * surfaces at this point, before freezeLines() mutates any row, and is
     * re-thrown as InvoiceFxRateInvalid (caught by the controller as a clean
     * 422 on the fx_rate field). Without this probe a plausible-but-malformed
     * rate like '280.123456789' (9dp) would pass a hand-rolled shape check
     * here and then throw FxConverter's raw InvalidArgumentException deep
     * inside convertToBase() — which, not being an InvoiceSendException, would
     * escape the controller's catch as an uncaught 500. Delegating to the one
     * guard keeps this method and FxConverter from drifting apart.
     */
    private function resolveFxRate(Invoice $invoice, ?string $fxRate): string
    {
        $baseCurrency = $this->context->get()->base_currency;

        if ($invoice->currency_code === $baseCurrency) {
            return '1';
        }

        $trimmed = trim((string) $fxRate);

        try {
            // Probe-convert a nominal amount purely to run FxConverter's guard
            // (the result is discarded) — the single source of truth for what
            // a valid fx_rate looks like.
            FxConverter::toBase(1, $trimmed);
        } catch (InvalidArgumentException) {
            throw new InvoiceFxRateInvalid(
                "A valid FX rate (a positive number with up to 8 decimal places) is required to send a {$invoice->currency_code} invoice (base currency is {$baseCurrency})."
            );
        }

        return $trimmed;
    }

    /**
     * Converts each already-frozen line component to base minor units via
     * FxConverter::toBase, then sums — the component-wise discipline spec
     * §17.1 requires (see FxConverter's docblock). Shape mirrors $computed
     * from freezeLines(): same line ordering/index, plus document-level
     * subtotal/tax_total/total in BASE minor units.
     *
     * @param  array{lines: array<int, array{line_subtotal:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}  $computed
     * @return array{lines: array<int, array{base_line_subtotal:int, base_line_tax:int}>, subtotal:int, tax_total:int, total:int}
     */
    private function convertToBase(array $computed, string $fxRate): array
    {
        $baseLines = [];
        $subtotal = 0;
        $taxTotal = 0;

        foreach ($computed['lines'] as $line) {
            $baseLineSubtotal = FxConverter::toBase($line['line_subtotal'], $fxRate);
            $baseLineTax = FxConverter::toBase($line['line_tax'], $fxRate);

            $baseLines[] = [
                'base_line_subtotal' => $baseLineSubtotal,
                'base_line_tax' => $baseLineTax,
            ];

            $subtotal += $baseLineSubtotal;
            $taxTotal += $baseLineTax;
        }

        return [
            'lines' => $baseLines,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal,
        ];
    }

    private function guard(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new InvoiceNotDraft('Only a draft invoice can be sent.');
        }
        if ($invoice->lines->isEmpty()) {
            throw new InvoiceHasNoLines('An invoice must have at least one line before it can be sent.');
        }
        if (! $invoice->contact->type->isCustomer()) {
            throw new InvoiceContactNotEligible('The invoice contact must be a customer (or both) to send an invoice.');
        }
    }

    /**
     * Voids a sent/partially-paid/paid invoice: posting rule P9 (spec §6) —
     * a mirror reversal of the invoice's own P1 entry (Cr AR / Dr income per
     * line / Dr tax), via LedgerService::reverse. Accounting-correct ordering
     * (spec §6 note): payments must already be voided one by one via
     * InvoicePaymentService::voidPayment BEFORE the invoice itself can be
     * voided — this method deliberately does NOT auto-reverse them; it
     * REFUSES outright while any live (non-void) payment remains, so the
     * user consciously reverses each one first.
     *
     * $date defaults to today at the controller layer (locked-period safe
     * even when the ORIGINAL entry's period has since been locked — spec §4
     * rule 4: a reversal must always be postable in an open period).
     *
     * Shape mirrors send(): IdempotencyService::execute -> DB::transaction ->
     * locked read -> guards -> LedgerService::reverse -> document mutation ->
     * AuditLogger.
     */
    public function void(string $idempotencyKey, Invoice $invoice, string $date, string $memo): Invoice
    {
        $result = $this->idempotency->execute($idempotencyKey, 'invoice.voided', function () use ($invoice, $date, $memo) {
            return DB::transaction(function () use ($invoice, $date, $memo) {
                // Row-lock + re-read FRESH state, same reasoning as send()'s
                // locked read: this MUST see the CURRENT status/payments, not
                // whatever the controller resolved moments before this
                // closure runs (and on an idempotency replay it doesn't run
                // at all). Locking here is also what serializes a concurrent
                // voidPayment() against the same invoice (that method locks
                // the invoice row too) and a concurrent second void() call.
                $locked = Invoice::query()->where('id', $invoice->id)->lockForUpdate()->firstOrFail();

                $this->guardVoid($locked);

                $entry = JournalEntry::query()
                    ->where('source_type', 'invoice')
                    ->where('source_id', $locked->id)
                    ->where('entry_role', EntryRole::Primary->value)
                    ->firstOrFail();

                $reversal = $this->ledger->reverse($entry, CarbonImmutable::parse($date), $memo);

                $locked->forceFill([
                    'status' => InvoiceStatus::Void->value,
                    'voided_at' => now(),
                ])->save();

                $this->audit->log('invoice.voided', $locked, after: ['reversal_entry_id' => $reversal->id]);

                return ['id' => $locked->id];
            });
        });

        return Invoice::query()->findOrFail($result['id']);
    }

    /**
     * Status guard mirrors InvoiceStatus::canVoid() (sent/partially_paid/paid
     * only), with a friendlier "already voided" message for that specific
     * case. The live-payments guard queries the LOCKED invoice's payments
     * fresh (not a possibly-stale relation) so it can never miss a payment
     * recorded moments earlier in a concurrent transaction that already
     * committed and released the lock this method is holding.
     */
    private function guardVoid(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw new InvoiceNotVoidable('This invoice has already been voided.');
        }
        if (! $invoice->status->canVoid()) {
            throw new InvoiceNotVoidable('Only a sent, partially paid, or paid invoice can be voided.');
        }

        $livePayments = $invoice->payments()->where('status', '!=', 'void')->pluck('id');
        if ($livePayments->isNotEmpty()) {
            $ids = $livePayments->implode(', ');
            throw new InvoiceHasLivePayments(
                "Reverse the invoice's payment(s) first (payment ID(s): {$ids})."
            );
        }
    }

    /**
     * Freezes each line's tax rate into tax_rate_snapshot and recomputes
     * line_subtotal/line_tax from that frozen rate via InvoiceCalculator
     * (they should already match the draft's own totals — this is a
     * defensive recompute, not a trust-the-draft copy). Must run before the
     * invoice's status flips away from draft (see class docblock).
     *
     * @return array{lines: array<int, array{line_subtotal:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}
     */
    private function freezeLines(Invoice $invoice): array
    {
        $lines = $invoice->lines;
        $taxRateIds = $lines->pluck('tax_rate_id')->filter()->unique()->values();
        $taxRates = TaxRate::query()->whereIn('id', $taxRateIds)->get()->keyBy('id');

        $calculatorLines = $lines->map(function (InvoiceLine $line) use ($taxRates) {
            $taxRate = $line->tax_rate_id !== null ? $taxRates->get($line->tax_rate_id) : null;

            return [
                'quantity' => (string) $line->quantity,
                'unit_price' => $line->unit_price,
                'tax_rate' => $taxRate !== null ? (string) $taxRate->rate : null,
            ];
        })->all();

        $computed = InvoiceCalculator::compute($calculatorLines);

        foreach ($lines->values() as $index => $line) {
            $taxRate = $line->tax_rate_id !== null ? $taxRates->get($line->tax_rate_id) : null;
            $line->forceFill([
                'tax_rate_snapshot' => $taxRate?->rate,
                'line_subtotal' => $computed['lines'][$index]['line_subtotal'],
                'line_tax' => $computed['lines'][$index]['line_tax'],
            ])->save();
        }

        return $computed;
    }

    /**
     * Posting rule P1/P1fx (spec §6/§17.1): Dr 1100 AR for the invoice's BASE
     * total (WITH contact_id — LedgerService requires it on every
     * control-account line); Cr each line's income account for its
     * (just-converted) BASE subtotal; Cr 2100 Tax Payable for the BASE total
     * tax as ONE combined line (not per-line), omitted entirely when there's
     * no tax at all. AR always equals Σincome + tax, so the entry balances by
     * construction — for a domestic invoice $base already equals $computed
     * (FxConverter at rate 1 is an identity conversion), and for a foreign
     * one $base holds the component-wise-converted PKR amounts, which is now
     * a fixed base-currency liability never re-measured at payment time
     * (spec §17.1: tax is a base-currency liability from send).
     *
     * A $0-value line (base_line_subtotal === 0 — a genuine $0 doc-currency
     * line, or a small positive doc amount that rounds down to 0 base minor
     * units at a fractional rate < 1) contributes nothing to the ledger, so
     * it produces NO Cr income LineDraft: a zero-value line would trip
     * LedgerService's "exactly one side positive" rule. It's filtered
     * defensively regardless of how the 0 arises — not on an assumption about
     * the rate's magnitude (PKR-per-USD rates are ≫1, but the base<foreign
     * direction is a legitimate future case). The line still exists on the
     * invoice document (for display) — it just isn't posted. The all-lines-$0
     * case can't reach here — send() rejects a zero document total with
     * InvoiceHasNoValue before this method is called.
     *
     * @param  array{lines: array<int, array{base_line_subtotal:int, base_line_tax:int}>, subtotal:int, tax_total:int, total:int}  $base
     * @return list<LineDraft>
     */
    private function p1Lines(Invoice $invoice, array $base): array
    {
        $arAccount = Account::query()->where('code', '1100')->firstOrFail();

        $lines = [
            new LineDraft(accountId: $arAccount->id, debit: $base['total'], credit: 0, contactId: $invoice->contact_id),
        ];

        foreach ($invoice->lines->values() as $index => $line) {
            $baseLineSubtotal = $base['lines'][$index]['base_line_subtotal'];
            if ($baseLineSubtotal === 0) {
                continue;
            }
            $lines[] = new LineDraft(
                accountId: $line->income_account_id,
                debit: 0,
                credit: $baseLineSubtotal,
            );
        }

        if ($base['tax_total'] > 0) {
            $taxPayable = Account::query()->where('code', '2100')->firstOrFail();
            $lines[] = new LineDraft(accountId: $taxPayable->id, debit: 0, credit: $base['tax_total']);
        }

        return $lines;
    }
}
