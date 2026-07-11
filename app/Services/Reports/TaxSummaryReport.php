<?php

namespace App\Services\Reports;

use App\Enums\EntryRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tax Summary (spec §7.11.6): net movement of 2100 Tax Payable over a
 * [from, to] period, split collected vs. input BY SOURCE DOCUMENT TYPE
 * (signed) — NEVER by which column (debit/credit) an amount sits in.
 *
 * THE CLASSIFICATION TRAP this whole class exists to avoid: a reversal
 * mirrors its original entry with debit/credit SWAPPED (LedgerService::
 * reverse()), so a naive "debit = input, credit = collected" rule would
 * misclassify a voided invoice's reversal (which DEBITS 2100) as input tax,
 * when it is really a DECREASE in collected tax. The fix (mirrors
 * IntegrityChecker's own source-type discipline): every 2100 line is
 * attributed to a bucket by its ENTRY's `source_type` (journal_entries),
 * never by its own debit/credit side. A reversal carries the SAME
 * source_type/source_id as what it reverses (spec §4 iron rule), so it
 * always lands in the SAME bucket as the entry it undoes, just with the
 * opposite sign — collected correctly decreases when an invoice is voided,
 * and input correctly decreases when an expense/bill is voided.
 *
 * COLLECTED (output tax): every source_type='invoice' line on 2100 (P1 posts
 * a Cr; its reversal, sharing source_type='invoice', posts a Dr that nets
 * back down) — signed as (credit - debit), positive when tax is owed to the
 * authority.
 *
 * INPUT vs. REMITTANCES — the second-level split, spec §7.11.6's other
 * trap: expense/bill (P4/P5/P6t) DEBIT 2100 for two UNRELATED reasons that
 * can appear in the SAME journal entry and even on the SAME account:
 *   - the combined tax_total line (input tax on a taxable purchase), and
 *   - a P12 tax remittance: an expense line whose own CATEGORY account IS
 *     2100 (paying the tax authority), posted by ExpenseService::p4Lines'
 *     ordinary per-line category Dr — entirely independent of the tax_total
 *     line, per that method's own docblock.
 * journal_lines carries no column distinguishing these two cases (both are
 * plain Dr 2100 lines) — see ExpenseService::p4Lines/BillService::p6Lines.
 * The only reliable discriminator is the SOURCE DOCUMENT's own frozen
 * fields (immutable once posted, exactly like the ledger lines they
 * produced — see ExpenseLine/BillLine's freeze guard):
 *   - tax_total (Expense/Bill) = the input-tax component,
 *   - Σ expense_lines/bill_lines.amount WHERE category_account_id = 2100
 *     = the remittance component.
 * This mirrors AgingReport's own precedent (spec §7.11 preamble): the one
 * narrow, documented exception where a report joins to the source document
 * — here for CLASSIFICATION only, never for the amount itself (the amount
 * used is the same frozen figure LedgerService posted to the ledger, so
 * this still ties out to the ledger by construction — see the class-level
 * tie-out test).
 *
 * ADJUSTMENTS — everything else, computed as a RESIDUAL so the tie-out is
 * STRUCTURAL rather than dependent on the three named buckets happening to
 * be exhaustive. Collected covers source_type='invoice'; input+remittances
 * cover the whole of expense/bill's 2100 movement (an expense/bill debits
 * 2100 only via its combined tax_total line + its category-2100 lines, and
 * nothing else — see ExpenseService::p4Lines/BillService::p6Lines — so those
 * two figures reconstruct its 2100 movement exactly). Every OTHER 2100
 * movement — a manual_journal adjustment (P) AND, crucially, an
 * other_receipt whose category is 2100 (OtherReceiptService's category guard
 * excludes Receivable/Payable/Cash/Bank but NOT the Tax subtype, so an
 * other-receipt CAN legitimately credit 2100), AND any future/unexpected
 * source_type that touches 2100 — is captured here by DEFINITION:
 *
 *     adjustments = totalMovement - collected + input + remittances
 *
 * where totalMovement is the WHOLE account's (credit - debit) net over the
 * period. Because collected contributes +collected to that total, input
 * contributes -input, and remittances contributes -remittances (the sign
 * reasoning below), this residual is exactly "all 2100 movement not already
 * attributed" — manual + other adjustments to tax. The "Adjustments" line
 * label stays; it now honestly means that. This is deliberately NOT a plain
 * Σ(manual_journal) sum: an earlier version of this report only bucketed
 * manual_journal and silently stopped tying to the ledger the moment an
 * other_receipt (or anything else) credited 2100 — the residual approach
 * makes that class of drift impossible by construction.
 *
 * SIGN REASONING (all in the "liability increased" convention = credit -
 * debit, which is the negation of LedgerBalances' debit - credit net):
 *   - collected  : an invoice CREDITS 2100 → +collected to the movement.
 *   - input      : an expense/bill DEBITS 2100 by tax_total → -input.
 *   - remittances: an expense/bill DEBITS 2100 by its 2100-category lines
 *                  → -remittances.
 *
 * TIE-OUT (load-bearing, see TaxSummaryReportTest): net_tax_payable ==
 * collected - input - remittances + adjustments == totalMovement == the
 * WHOLE account's (credit - debit) net over the period == -1 *
 * LedgerBalances::movement($from, $to, [2100])['net'] — now true BY
 * CONSTRUCTION for any mix of source types, not just the currently-known
 * ones.
 *
 * READ-ONLY: this class performs no ledger writes.
 */
class TaxSummaryReport
{
    public function __construct(private LedgerBalances $ledger) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     collected: int,
     *     input: int,
     *     remittances: int,
     *     adjustments: int,
     *     net_tax_payable: int,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        // Nullable, not firstOrFail: a company with no 2100 account (e.g.
        // no tax rates ever configured) simply reports all zeros rather than
        // 500ing — mirrors BalanceSheetReport's own handling of a missing
        // 3200 Retained Earnings account.
        $taxAccount = Account::query()->where('code', '2100')->first();

        if ($taxAccount === null) {
            return $this->zeroResult($from, $to);
        }

        $collected = $this->sourceTypeTotal($from, $to, $taxAccount->id, 'invoice');
        [$input, $remittances] = $this->inputAndRemittanceTotals($from, $to, $taxAccount->id);

        // Whole-account 2100 net movement over the period, in the
        // liability-increase convention (credit - debit). LedgerBalances'
        // net is debit - credit, so negate. This is the ground truth the
        // report must tie to — making adjustments its RESIDUAL (below) is
        // what makes that tie STRUCTURAL, see class docblock.
        $movement = $this->ledger->movement($from, $to, [$taxAccount->id]);
        $totalMovement = -($movement[$taxAccount->id]['net'] ?? 0);

        // Everything not already attributed to collected/input/remittances:
        // manual_journal, other_receipt→2100, and any future source. Derived,
        // not summed — so net_tax_payable == totalMovement by construction.
        $adjustments = $totalMovement - $collected + $input + $remittances;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'collected' => $collected,
            'input' => $input,
            'remittances' => $remittances,
            'adjustments' => $adjustments,
            'net_tax_payable' => $collected - $input - $remittances + $adjustments,
        ];
    }

    /**
     * @return array{from: string, to: string, collected: int, input: int, remittances: int, adjustments: int, net_tax_payable: int}
     */
    private function zeroResult(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'collected' => 0,
            'input' => 0,
            'remittances' => 0,
            'adjustments' => 0,
            'net_tax_payable' => 0,
        ];
    }

    /**
     * Straightforward (credit - debit) net of every 2100 line whose entry has
     * the given `source_type`, dated within [from, to] — used for collected
     * (source_type='invoice'), which has no P12-style second component to
     * split out (see class docblock). A reversal shares the same source_type
     * as its original (spec §4), so it nets back down automatically within
     * this same sum — never filtered out.
     */
    private function sourceTypeTotal(CarbonImmutable $from, CarbonImmutable $to, int $taxAccountId, string $sourceType): int
    {
        $net = JournalLine::query()
            ->where('account_id', $taxAccountId)
            ->whereHas('journalEntry', fn ($e) => $e->where('source_type', $sourceType)
                ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->selectRaw('SUM(credit) - SUM(debit) AS net')
            ->value('net');

        return (int) ($net ?? 0);
    }

    /**
     * The input-tax / remittance split for source_type IN ('expense','bill')
     * (see class docblock for the full reasoning). Only entries that
     * actually carry a 2100 line are considered (whereHas('lines', ...)) —
     * the split AMOUNTS still come from the source document's own frozen
     * columns, but which entries are in scope at all is determined from the
     * ledger itself, same as every other report.
     *
     * @return array{0: int, 1: int} [input, remittances]
     */
    private function inputAndRemittanceTotals(CarbonImmutable $from, CarbonImmutable $to, int $taxAccountId): array
    {
        $entries = JournalEntry::query()
            ->whereIn('source_type', ['expense', 'bill'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('lines', fn ($l) => $l->where('account_id', $taxAccountId))
            ->get(['id', 'source_type', 'source_id', 'entry_role']);

        if ($entries->isEmpty()) {
            return [0, 0];
        }

        $expenseIds = $entries->where('source_type', 'expense')->pluck('source_id')->unique()->values();
        $billIds = $entries->where('source_type', 'bill')->pluck('source_id')->unique()->values();

        $expenseTaxTotals = $expenseIds->isEmpty() ? collect() : Expense::query()->whereIn('id', $expenseIds)->pluck('tax_total', 'id');
        $billTaxTotals = $billIds->isEmpty() ? collect() : Bill::query()->whereIn('id', $billIds)->pluck('tax_total', 'id');

        $expenseRemittances = $this->remittanceAmountsByDocument(ExpenseLine::class, 'expense_id', $expenseIds, $taxAccountId);
        $billRemittances = $this->remittanceAmountsByDocument(BillLine::class, 'bill_id', $billIds, $taxAccountId);

        $input = 0;
        $remittances = 0;

        foreach ($entries as $entry) {
            // A reversal carries the SAME source_id as its original (spec
            // §4) and mirrors its debit/credit — so its direction here is
            // simply flipped, which correctly nets a voided expense/bill's
            // input tax AND remittance back down, independently of one
            // another.
            $direction = $entry->entry_role === EntryRole::Primary ? 1 : -1;

            if ($entry->source_type === 'expense') {
                $taxTotal = (int) ($expenseTaxTotals[$entry->source_id] ?? 0);
                $remit = (int) ($expenseRemittances[$entry->source_id] ?? 0);
            } else {
                $taxTotal = (int) ($billTaxTotals[$entry->source_id] ?? 0);
                $remit = (int) ($billRemittances[$entry->source_id] ?? 0);
            }

            $input += $direction * $taxTotal;
            $remittances += $direction * $remit;
        }

        return [$input, $remittances];
    }

    /**
     * Σ amount per document id, for every expense_lines/bill_lines row whose
     * category_account_id IS 2100 — the P12 remittance component, frozen and
     * immutable once its document is recorded/received (same freeze guard
     * that protects the amounts LedgerService already posted from it).
     *
     * @param  class-string<ExpenseLine|BillLine>  $lineModel
     * @param  Collection<int, int>  $documentIds
     * @return Collection<int, int> document id => Σ remittance amount
     */
    private function remittanceAmountsByDocument(string $lineModel, string $foreignKey, Collection $documentIds, int $taxAccountId): Collection
    {
        if ($documentIds->isEmpty()) {
            return collect();
        }

        return $lineModel::query()
            ->whereIn($foreignKey, $documentIds)
            ->where('category_account_id', $taxAccountId)
            ->selectRaw("{$foreignKey}, SUM(amount) AS amt")
            ->groupBy($foreignKey)
            ->pluck('amt', $foreignKey);
    }
}
