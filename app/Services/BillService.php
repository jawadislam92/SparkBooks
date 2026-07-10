<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\BillStatus;
use App\Exceptions\Documents\BillContactNotEligible;
use App\Exceptions\Documents\BillDueDateRequired;
use App\Exceptions\Documents\DocumentHasNoLines;
use App\Exceptions\Documents\DocumentHasNoValue;
use App\Exceptions\Documents\DocumentNotDraft;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Receives a draft bill: the AP-side sibling of ExpenseService::record (this
 * same Phase 4 task) — identical shape, different posting target. Guards
 * it's still postable, re-validates every line's category account
 * (service-level, caller-independent — CategoryAccountGuard::guard), freezes
 * each line's tax rate + recomputes totals via DocumentLineCalculator, posts
 * posting rule P6/P6t (per line Dr the category account for its net amount;
 * if any tax, Dr ONE combined 2100 Tax Payable line for the tax total; Cr
 * 2000 Accounts Payable for the gross total WITH the bill's contact_id —
 * LedgerService requires a contact on every control-account line) via
 * LedgerService, and flips the bill to Received (amount_paid=0,
 * balance=total — payments are a later Phase 4 task).
 *
 * Shape mirrors ExpenseService::record exactly (see its own docblock for the
 * full "why this order" reasoning) — the only differences are: an extra
 * vendor-contact guard, a REQUIRED (not defaulted) due_date, and the AP
 * control-account credit line replacing the bank-account credit line.
 */
class BillService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    public function receive(string $idempotencyKey, Bill $draft): Bill
    {
        $result = $this->idempotency->execute($idempotencyKey, 'bill.received', function () use ($draft) {
            return DB::transaction(function () use ($draft) {
                // Re-read fresh inside the transaction — mirrors
                // ExpenseService::record's own reasoning exactly.
                $bill = Bill::query()
                    ->with(['contact', 'lines' => fn ($q) => $q->orderBy('id')])
                    ->findOrFail($draft->id);

                $this->guard($bill);
                $this->guardLineCategories($bill);

                // bill_date defaults to today if the draft never set one
                // (mirrors ExpenseService::record's expense_date default).
                // due_date has NO such default — guard() above already
                // rejected a null one; it is a hard requirement, not a
                // fill-in, per the Phase 4 brief.
                $billDate = $bill->bill_date?->toDateString() ?? CarbonImmutable::today()->toDateString();

                // Freeze each line's tax rate + recompute totals WHILE the
                // bill is still draft — BillLine::booted()'s freeze guard
                // rejects line writes once status leaves draft.
                $computed = $this->freezeLines($bill);

                if ($computed['total'] <= 0) {
                    throw new DocumentHasNoValue('A bill must have a total greater than zero to be received.');
                }

                $bill->forceFill([
                    'status' => BillStatus::Received->value,
                    'bill_date' => $billDate,
                    'subtotal' => $computed['subtotal'],
                    'tax_total' => $computed['tax_total'],
                    'total' => $computed['total'],
                    'amount_paid' => 0,
                    'balance' => $computed['total'],
                    'received_at' => now(),
                ])->save();

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($billDate),
                    memo: $bill->reference !== null ? "Bill {$bill->reference}" : "Bill #{$bill->id}",
                    sourceType: 'bill',
                    sourceId: $bill->id,
                    lines: $this->p6Lines($bill, $computed),
                ));

                $this->audit->log('bill.received', $bill, after: [
                    'entry_id' => $entry->id,
                    'total' => $bill->total,
                ]);

                return ['id' => $bill->id];
            });
        });

        return Bill::query()->findOrFail($result['id']);
    }

    private function guard(Bill $bill): void
    {
        if ($bill->status !== BillStatus::Draft) {
            throw new DocumentNotDraft('Only a draft bill can be received.');
        }
        if ($bill->lines->isEmpty()) {
            throw new DocumentHasNoLines('A bill must have at least one line before it can be received.');
        }
        // Re-guard: StoreBillRequest already enforces this at draft-write
        // time (Task 2), but the contact's type may have changed since, or a
        // row may reach this method some other way — mirrors
        // InvoiceService::guard's own contact re-check (Phase 3 T3).
        if (! $bill->contact->type->isVendor()) {
            throw new BillContactNotEligible('The bill contact must be a vendor (or both) to receive a bill.');
        }
        if ($bill->due_date === null) {
            throw new BillDueDateRequired('A due date is required to receive a bill.');
        }
    }

    /** Mirrors ExpenseService::guardLineCategories exactly. */
    private function guardLineCategories(Bill $bill): void
    {
        foreach ($bill->lines as $line) {
            $categoryAccount = Account::query()->findOrFail($line->category_account_id);
            CategoryAccountGuard::guard($categoryAccount);
        }
    }

    /**
     * Mirrors ExpenseService::freezeLines exactly (see its own docblock).
     *
     * @return array{lines: array<int, array{amount:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}
     */
    private function freezeLines(Bill $bill): array
    {
        $lines = $bill->lines;
        $taxRateIds = $lines->pluck('tax_rate_id')->filter()->unique()->values();
        $taxRates = TaxRate::query()->whereIn('id', $taxRateIds)->get()->keyBy('id');

        $calculatorLines = $lines->map(function (BillLine $line) use ($taxRates) {
            $taxRate = $line->tax_rate_id !== null ? $taxRates->get($line->tax_rate_id) : null;

            return [
                'amount' => $line->amount,
                'tax_rate' => $taxRate !== null ? (string) $taxRate->rate : null,
            ];
        })->all();

        $computed = DocumentLineCalculator::compute($calculatorLines);

        foreach ($lines->values() as $index => $line) {
            $taxRate = $line->tax_rate_id !== null ? $taxRates->get($line->tax_rate_id) : null;
            $line->forceFill([
                'tax_rate_snapshot' => $taxRate?->rate,
                'line_tax' => $computed['lines'][$index]['line_tax'],
            ])->save();
        }

        return $computed;
    }

    /**
     * Posting rule P6/P6t (spec §6): per line Dr the category account for
     * its net amount; if any tax, Dr 2100 Tax Payable for the combined
     * tax_total as ONE line; Cr 2000 Accounts Payable for the gross total,
     * WITH contact_id (LedgerService requires a contact on every
     * control-account line — 2000's Payable subtype makes this mandatory
     * regardless, but it's passed explicitly here for clarity and so the
     * ledger line is correctly attributable to the vendor even before that
     * enforcement is considered). $0-amount lines filtered — mirrors
     * ExpenseService::p4Lines exactly (see its own docblock for the full
     * reasoning, incl. the P12 category-IS-2100 case).
     *
     * @param  array{lines: array<int, array{amount:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}  $computed
     * @return list<LineDraft>
     */
    private function p6Lines(Bill $bill, array $computed): array
    {
        $lines = [];

        foreach ($bill->lines->values() as $index => $line) {
            $amount = $computed['lines'][$index]['amount'];
            if ($amount === 0) {
                continue;
            }
            $lines[] = new LineDraft(accountId: $line->category_account_id, debit: $amount, credit: 0);
        }

        if ($computed['tax_total'] > 0) {
            $taxPayable = Account::query()->where('code', '2100')->firstOrFail();
            $lines[] = new LineDraft(accountId: $taxPayable->id, debit: $computed['tax_total'], credit: 0);
        }

        $apAccount = Account::query()->where('code', '2000')->firstOrFail();
        $lines[] = new LineDraft(accountId: $apAccount->id, debit: 0, credit: $computed['total'], contactId: $bill->contact_id);

        return $lines;
    }
}
