<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Enums\ExpenseStatus;
use App\Exceptions\Documents\DocumentHasNoLines;
use App\Exceptions\Documents\DocumentHasNoValue;
use App\Exceptions\Documents\DocumentNotDraft;
use App\Exceptions\Documents\DocumentNotVoidable;
use App\Exceptions\Documents\ExpenseBankAccountRequired;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a draft expense: guards it's still postable, re-validates every
 * line's category account (service-level, caller-independent —
 * CategoryAccountGuard::guard), freezes each line's tax rate + recomputes
 * totals via DocumentLineCalculator, posts posting rule P4/P5 (per line Dr
 * the category account for its net amount; if any tax, Dr ONE combined 2100
 * Tax Payable line for the tax total — input tax is a DEBIT, it nets against
 * output tax rather than accruing as a liability credit; Cr the paid-from
 * bank/cash account's linked ledger account for the gross total) via
 * LedgerService, and flips the expense to Recorded.
 *
 * The AP-side (money-out) mirror of InvoiceService::send (Phase 3 T3), and
 * the sibling of BillService::receive (this same Phase 4 task — identical
 * shape, BillService posts to 2000 AP instead of a bank account). Shape
 * mirrors InvoiceService::send exactly: IdempotencyService::execute ->
 * DB::transaction -> fresh re-read -> guards -> freeze lines BEFORE the
 * status flip (ExpenseLine::booted()'s freeze guard rejects line writes once
 * status leaves draft, so this order is load-bearing, not stylistic) ->
 * degenerate-value guard -> LedgerService::post -> status flip -> Audit.
 */
class ExpenseService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    public function record(string $idempotencyKey, Expense $draft): Expense
    {
        $result = $this->idempotency->execute($idempotencyKey, 'expense.recorded', function () use ($draft) {
            return DB::transaction(function () use ($draft) {
                // Re-read fresh inside the transaction: $draft may have been
                // resolved by the controller moments before this closure
                // runs (and on an idempotency replay this closure doesn't
                // execute at all), so this guarantees the guards below always
                // see the CURRENT row/lines. Mirrors InvoiceService::send.
                $expense = Expense::query()
                    ->with(['lines' => fn ($q) => $q->orderBy('id')])
                    ->findOrFail($draft->id);

                $this->guard($expense);
                $bankAccount = $this->resolveBankAccount($expense);
                $this->guardLineCategories($expense);

                // Resolved BEFORE freezeLines() mutates any line row, exactly
                // like InvoiceService::send resolves fx_rate first — a
                // missing expense_date defaults to today (unlike
                // bank_account_id, this is a fill-in, not a hard rejection).
                $expenseDate = $expense->expense_date?->toDateString() ?? CarbonImmutable::today()->toDateString();

                // Freeze each line's tax rate + recompute totals WHILE the
                // expense is still draft — ExpenseLine::booted()'s freeze
                // guard rejects line writes once status leaves draft, so this
                // MUST happen before the status flip below.
                $computed = $this->freezeLines($expense);

                // Degenerate case: every line is $0 and there's no tax, so
                // the computed total is 0 — nothing to post, and a zero Dr
                // category line would trip LedgerService's one-sided rule.
                // Mirrors InvoiceService::send's InvoiceHasNoValue guard.
                if ($computed['total'] <= 0) {
                    throw new DocumentHasNoValue('An expense must have a total greater than zero to be recorded.');
                }

                $expense->forceFill([
                    'status' => ExpenseStatus::Recorded->value,
                    'expense_date' => $expenseDate,
                    'subtotal' => $computed['subtotal'],
                    'tax_total' => $computed['tax_total'],
                    'total' => $computed['total'],
                    'recorded_at' => now(),
                ])->save();

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($expenseDate),
                    memo: "Expense #{$expense->id}",
                    sourceType: 'expense',
                    sourceId: $expense->id,
                    lines: $this->p4Lines($expense, $computed, $bankAccount),
                ));

                $this->audit->log('expense.recorded', $expense, after: [
                    'entry_id' => $entry->id,
                    'total' => $expense->total,
                ]);

                return ['id' => $expense->id];
            });
        });

        return Expense::query()->findOrFail($result['id']);
    }

    private function guard(Expense $expense): void
    {
        if ($expense->status !== ExpenseStatus::Draft) {
            throw new DocumentNotDraft('Only a draft expense can be recorded.');
        }
        if ($expense->lines->isEmpty()) {
            throw new DocumentHasNoLines('An expense must have at least one line before it can be recorded.');
        }
    }

    /**
     * Voids a recorded expense: posting rule P9 (spec §6) — a mirror
     * reversal of the expense's own P4/P5 entry (Cr category / Cr 2100 Tax
     * Payable / Dr the paid-from bank account), via LedgerService::reverse.
     * The AP-side (money-out) mirror of InvoiceService::void (Phase 3 T5),
     * simplified — an expense has no live-payments concept (it's a single
     * cash-out event, not a document that accrues separate payments the way
     * a Bill does) and no is_opening concept either (Phase 4 T7's opening
     * payables land on Bill, not Expense), so this guard is just "posted,
     * and not already void."
     *
     * The reversal is always dated TODAY (mirrors
     * InvoicePaymentService::voidPayment, which likewise takes no date
     * parameter) — never the original expense_date — so it always lands in
     * an open period even if the original entry's period has since been
     * locked (spec §4 rule 4: a reversal must always be postable). If TODAY
     * itself is locked, LedgerService rejects it with LockedPeriod, exactly
     * as it would any other entry dated into a locked period.
     *
     * Shape mirrors record(): IdempotencyService::execute -> DB::transaction
     * -> lockForUpdate re-fetch -> guard -> LedgerService::reverse -> status
     * flip -> AuditLogger. The unique-constraint backstop for a genuine
     * concurrent double-void race lives in the CONTROLLER (mirrors
     * InvoiceController::void/ManualJournalController::reverse), not here —
     * this service's own lockForUpdate()+status-guard already serializes it
     * in the common case.
     */
    public function void(string $idempotencyKey, Expense $expense): Expense
    {
        $result = $this->idempotency->execute($idempotencyKey, 'expense.voided', function () use ($expense) {
            return DB::transaction(function () use ($expense) {
                // Row-lock + re-read FRESH state: $expense may have been
                // resolved by the controller moments before this closure
                // runs (and on an idempotency replay this closure doesn't
                // execute at all), so the guard below MUST see the CURRENT
                // status. Mirrors InvoiceService::void's own locked read.
                $locked = Expense::query()->where('id', $expense->id)->lockForUpdate()->firstOrFail();

                $this->guardVoid($locked);

                $entry = JournalEntry::query()
                    ->where('source_type', 'expense')
                    ->where('source_id', $locked->id)
                    ->where('entry_role', EntryRole::Primary->value)
                    ->firstOrFail();

                $reversal = $this->ledger->reverse(
                    $entry,
                    CarbonImmutable::today(),
                    "Void: Expense #{$locked->id}"
                );

                $locked->forceFill([
                    'status' => ExpenseStatus::Void->value,
                    'voided_at' => now(),
                ])->save();

                $this->audit->log('expense.voided', $locked, after: ['reversal_entry_id' => $reversal->id]);

                return ['id' => $locked->id];
            });
        });

        return Expense::query()->findOrFail($result['id']);
    }

    /**
     * Status guard mirrors ExpenseStatus::canVoid() (Recorded only), with a
     * friendlier "already voided" message for that specific case — Draft
     * falls through to the generic message (deleting a draft is the correct
     * action there, not voiding — ExpenseController::destroy already covers
     * it).
     */
    private function guardVoid(Expense $expense): void
    {
        if ($expense->status === ExpenseStatus::Void) {
            throw new DocumentNotVoidable('This expense has already been voided.');
        }
        if (! $expense->status->canVoid()) {
            throw new DocumentNotVoidable('Only a recorded expense can be voided.');
        }
    }

    /**
     * bank_account_id is nullable at draft (Task 1/2) but REQUIRED to
     * record — P4/P5 always Cr a bank/cash account for the gross total. Also
     * re-checks tenant ownership + non-archived: BankAccount's
     * BelongsToCompany global scope makes a foreign-tenant id
     * indistinguishable from a nonexistent one (both come back null here),
     * and an account archived after the draft was created must not be
     * postable against either.
     *
     * @throws ExpenseBankAccountRequired
     */
    private function resolveBankAccount(Expense $expense): BankAccount
    {
        if ($expense->bank_account_id === null) {
            throw new ExpenseBankAccountRequired('A bank/cash account is required to record an expense.');
        }

        $bankAccount = BankAccount::query()->find($expense->bank_account_id);

        if ($bankAccount === null || $bankAccount->is_archived) {
            throw new ExpenseBankAccountRequired('The selected bank/cash account is unavailable. Choose an active account.');
        }

        return $bankAccount;
    }

    /**
     * Re-validates every line's category account eligibility — the
     * CALLER-INDEPENDENT half of CategoryAccountGuard's dual-layer intent
     * (mirrors ExpenseController::createLines' own re-check at draft-write
     * time): an account archived, retyped, or otherwise made ineligible
     * AFTER the draft was created/edited must still block a record attempt,
     * not just a fresh draft write.
     */
    private function guardLineCategories(Expense $expense): void
    {
        foreach ($expense->lines as $line) {
            $categoryAccount = Account::query()->findOrFail($line->category_account_id);
            CategoryAccountGuard::guard($categoryAccount);
        }
    }

    /**
     * Freezes each line's tax rate into tax_rate_snapshot and recomputes
     * line_tax from that frozen rate via DocumentLineCalculator (should
     * already match the draft's own totals — a defensive recompute, not a
     * trust-the-draft copy). Must run before the expense's status flips away
     * from draft (see class docblock). Mirrors InvoiceService::freezeLines,
     * amount-based rather than qty x price (DocumentLineCalculator, T2).
     *
     * @return array{lines: array<int, array{amount:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}
     */
    private function freezeLines(Expense $expense): array
    {
        $lines = $expense->lines;
        $taxRateIds = $lines->pluck('tax_rate_id')->filter()->unique()->values();
        $taxRates = TaxRate::query()->whereIn('id', $taxRateIds)->get()->keyBy('id');

        $calculatorLines = $lines->map(function (ExpenseLine $line) use ($taxRates) {
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
     * Posting rule P4/P5 (spec §6): per line Dr the category account for its
     * net amount; if any tax, Dr 2100 Tax Payable for the combined tax_total
     * as ONE line (input tax is a DEBIT — it nets against output tax rather
     * than accruing as a liability); Cr the paid-from bank/cash account's
     * linked ledger account for the gross total. Balances by construction:
     * Σcategory(non-zero) + tax_total = total = the bank credit (a filtered
     * $0 line contributes 0 to subtotal either way, so filtering it changes
     * nothing about the balance).
     *
     * A $0-amount line produces NO Dr line — mirrors InvoiceService::
     * p1Lines' own $0-line filter (a zero-value line would trip
     * LedgerService's "exactly one side positive" rule). The all-lines-$0
     * case can't reach here — record() rejects a zero computed total with
     * DocumentHasNoValue first.
     *
     * A category account that happens to BE 2100 Tax Payable itself (P12,
     * tax remittance) still gets its own ordinary per-line Dr from the loop
     * below, entirely independent of the combined tax Dr line above —
     * LedgerService has no problem with two separate lines against the same
     * account, and "a category the user chose" vs. "tax computed on a line"
     * are unrelated concepts even when they happen to coincide on one
     * account code.
     *
     * @param  array{lines: array<int, array{amount:int, line_tax:int}>, subtotal:int, tax_total:int, total:int}  $computed
     * @return list<LineDraft>
     */
    private function p4Lines(Expense $expense, array $computed, BankAccount $bankAccount): array
    {
        $lines = [];

        foreach ($expense->lines->values() as $index => $line) {
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

        $lines[] = new LineDraft(accountId: $bankAccount->ledger_account_id, debit: 0, credit: $computed['total']);

        return $lines;
    }
}
