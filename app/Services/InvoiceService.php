<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\InvoiceStatus;
use App\Exceptions\Invoicing\InvoiceContactNotEligible;
use App\Exceptions\Invoicing\InvoiceHasNoLines;
use App\Exceptions\Invoicing\InvoiceHasNoValue;
use App\Exceptions\Invoicing\InvoiceNotDraft;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sends a draft invoice: assigns its document number, freezes each line's
 * tax rate, posts posting rule P1 (Dr 1100 AR / Cr income per line / Cr 2100
 * Tax Payable) via LedgerService, and flips the invoice to Sent — the first
 * ledger-posting invoice action (Phase 3 Task 3). Domestic-only: fx_rate is
 * always 1.0 here (real FX conversion lands in Task 7), so the base_* mirrors
 * always equal the (recomputed) document totals.
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
    ) {}

    public function send(string $idempotencyKey, Invoice $draft): Invoice
    {
        $result = $this->idempotency->execute($idempotencyKey, 'invoice.sent', function () use ($draft) {
            return DB::transaction(function () use ($draft) {
                // Re-read fresh inside the transaction: $draft may have been
                // resolved by the controller moments before this closure runs
                // (and on an idempotency replay this closure doesn't execute
                // at all), so this guarantees the guards below always see the
                // CURRENT row/lines/contact.
                $invoice = Invoice::query()
                    ->with(['contact', 'lines' => fn ($q) => $q->orderBy('id')])
                    ->findOrFail($draft->id);

                $this->guard($invoice);

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
                if ($computed['total'] <= 0) {
                    throw new InvoiceHasNoValue('An invoice must have a total greater than zero to be sent.');
                }

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
                    'fx_rate' => 1,
                    'subtotal' => $computed['subtotal'],
                    'tax_total' => $computed['tax_total'],
                    'total' => $computed['total'],
                    'amount_paid' => 0,
                    'balance' => $computed['total'],
                    'base_subtotal' => $computed['subtotal'],
                    'base_tax_total' => $computed['tax_total'],
                    'base_total' => $computed['total'],
                ])->save();

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($issueDate),
                    memo: "Invoice {$number}",
                    sourceType: 'invoice',
                    sourceId: $invoice->id,
                    lines: $this->p1Lines($invoice, $computed),
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
     * Posting rule P1 (spec §6): Dr 1100 AR for the invoice total (WITH
     * contact_id — LedgerService requires it on every control-account line);
     * Cr each line's income account for its (just-frozen) subtotal; Cr 2100
     * Tax Payable for the total tax as ONE combined line (not per-line),
     * omitted entirely when there's no tax at all. AR always equals
     * Σincome + tax, so the entry balances by construction.
     *
     * A $0-value line (line_subtotal === 0 — a legitimate free/promo line,
     * allowed by StoreInvoiceRequest's unit_price min:0) contributes nothing
     * to the ledger, so it produces NO Cr income LineDraft: a zero-value line
     * would trip LedgerService's "exactly one side positive" rule. The line
     * still exists on the invoice document (for display) — it just isn't
     * posted. The AR debit and tax are unaffected (a $0 line already adds 0
     * to both). The all-lines-$0 case can't reach here — send() rejects a
     * zero total with InvoiceHasNoValue before this method is called.
     *
     * @param  array{lines: array<int, array{line_subtotal:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}  $computed
     * @return list<LineDraft>
     */
    private function p1Lines(Invoice $invoice, array $computed): array
    {
        $arAccount = Account::query()->where('code', '1100')->firstOrFail();

        $lines = [
            new LineDraft(accountId: $arAccount->id, debit: $computed['total'], credit: 0, contactId: $invoice->contact_id),
        ];

        foreach ($invoice->lines->values() as $index => $line) {
            $lineSubtotal = $computed['lines'][$index]['line_subtotal'];
            if ($lineSubtotal === 0) {
                continue;
            }
            $lines[] = new LineDraft(
                accountId: $line->income_account_id,
                debit: 0,
                credit: $lineSubtotal,
            );
        }

        if ($computed['tax_total'] > 0) {
            $taxPayable = Account::query()->where('code', '2100')->firstOrFail();
            $lines[] = new LineDraft(accountId: $taxPayable->id, debit: 0, credit: $computed['tax_total']);
        }

        return $lines;
    }
}
