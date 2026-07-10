<?php

namespace App\Services;

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Models\OpeningBalanceRun;
use App\Models\OtherReceipt;
use App\Models\Transfer;
use App\Support\CompanyContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §9 correctness watchdog: verifies one company's ledger is internally
 * consistent. Every check runs inside a single CompanyContext::runFor +
 * DB::transaction so all checks see one consistent snapshot of the
 * company's data, rather than separate reads that could race against
 * concurrent posting.
 *
 * This class must never pass vacuously: an empty findings array means the
 * checks actually ran and found nothing wrong, not that the checks were
 * skipped or scoped to nothing. The caller (VerifyLedgerIntegrity) is
 * responsible for guarding against the latter — see its vacuous-scope check.
 */
class IntegrityChecker
{
    /**
     * Every ledger source_type this checker knows how to verify, mapped to
     * the model whose table holds the source document. Reversal entries are
     * NOT a source_type of their own — LedgerService::reverse() stamps a
     * reversal with the SAME source_type/source_id as the entry it reverses
     * (spec §4 iron rule), so they are covered by these same lookups without
     * any special-casing: the source row they point at is the original
     * document, which still exists.
     *
     * @var array<string, class-string<ManualJournal|Transfer|OtherReceipt|OpeningBalanceRun|Invoice|InvoicePayment|Expense|Bill>>
     */
    private const SOURCE_TABLES = [
        'manual_journal' => ManualJournal::class,
        'transfer' => Transfer::class,
        'other_receipt' => OtherReceipt::class,
        'opening_balance_run' => OpeningBalanceRun::class,
        'invoice' => Invoice::class,
        'invoice_payment' => InvoicePayment::class,
        // Phase 4 T3/T4: ExpenseService::record/BillService::receive post
        // source_type 'expense'/'bill' (P4/P5/P6/P6t) — registered here from
        // the moment they exist so a real posted expense/bill is never
        // mistaken for an unrecognized source (see
        // IntegrityCheckerTest::test_unknown_source_type_produces_unrecognized_source_finding's
        // own docblock, which used 'bill' as its stand-in until this task).
        'expense' => Expense::class,
        'bill' => Bill::class,
    ];

    /**
     * Posted invoice statuses (spec §9 check (c)): a draft has no ledger
     * entries to reconcile, and a void invoice's own P1 entry has already
     * been reversed to net zero — checking either would be meaningless (a
     * void invoice legitimately has a stale, non-reconciling `balance`/
     * `base_total` since those columns are simply never touched by
     * InvoiceService::void(), which only flips status/voided_at).
     */
    private const POSTED_INVOICE_STATUSES = [
        InvoiceStatus::Sent->value,
        InvoiceStatus::PartiallyPaid->value,
        InvoiceStatus::Paid->value,
    ];

    /** @return list<array{check:string, detail:string}> findings (empty = clean) */
    public function checkCompany(Company $company): array
    {
        return app(CompanyContext::class)->runFor($company, function () {
            return DB::transaction(function () {
                $findings = [];

                // (a) every journal entry balances: debits must equal credits
                // per entry, not just in aggregate.
                $unbalanced = JournalLine::query()
                    ->selectRaw('journal_entry_id, SUM(debit) AS d, SUM(credit) AS c')
                    ->groupBy('journal_entry_id')
                    ->havingRaw('SUM(debit) <> SUM(credit)')
                    ->get();
                foreach ($unbalanced as $row) {
                    $findings[] = ['check' => 'entry_balance', 'detail' => "Entry {$row->journal_entry_id} off by ".($row->d - $row->c)];
                }

                // (b) trial balance: the whole ledger must net to zero.
                $net = (int) JournalLine::query()->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');
                if ($net !== 0) {
                    $findings[] = ['check' => 'trial_balance', 'detail' => "Ledger net {$net} (should be 0)"];
                }

                // (f) every line posted to a receivable/payable control account
                // must carry a contact_id — otherwise AR/AP subledger reports
                // can't attribute the balance to anyone. Phase 2 added a real
                // FK on journal_lines.contact_id (composite on
                // (contact_id, company_id) -> contacts.(id, company_id)), so a
                // non-null contact_id is now guaranteed to reference a real,
                // same-company contact. That FK can't enforce NON-null,
                // though — a contact_id-less control-account line is still
                // valid at the DB level — so this check remains the only
                // guard against that specific gap.
                $missing = JournalLine::query()
                    ->whereIn('account_id', Account::query()->whereIn('subtype', ['receivable', 'payable'])->pluck('id'))
                    ->whereNull('contact_id')->count();
                if ($missing > 0) {
                    $findings[] = ['check' => 'control_contact', 'detail' => "{$missing} control-account line(s) missing contact"];
                }

                // (e) every ledger entry's source document must still exist.
                // Source-type-aware: for each source_type actually present on
                // this company's entries, look it up against the table
                // SOURCE_TABLES says it lives in. A source_type that ISN'T in
                // that map is not silently skipped — it is its own finding
                // (unrecognized_source), so a future source type someone
                // forgot to register here fails loud instead of quietly
                // passing every check.
                $sourceTypesPresent = JournalEntry::query()->distinct()->pluck('source_type');

                foreach ($sourceTypesPresent as $sourceType) {
                    if (! array_key_exists($sourceType, self::SOURCE_TABLES)) {
                        $count = JournalEntry::query()->where('source_type', $sourceType)->count();
                        $findings[] = ['check' => 'unrecognized_source', 'detail' => "{$count} ledger entr(ies) reference unrecognized source_type '{$sourceType}' (not registered in IntegrityChecker::SOURCE_TABLES)"];

                        continue;
                    }

                    $model = self::SOURCE_TABLES[$sourceType];
                    $orphaned = JournalEntry::query()->where('source_type', $sourceType)
                        ->whereNotIn('source_id', $model::query()->pluck('id'))->count();
                    if ($orphaned > 0) {
                        $findings[] = ['check' => 'orphan_entries', 'detail' => "{$orphaned} ledger entr(ies) reference missing {$sourceType}(s)"];
                    }
                }

                // (c) invoice/payment balance checks (spec §9, split for FX
                // per §17.1) — document-currency + base-currency-AR, each its
                // own finding type so an alert names exactly which reconciles
                // wrong.
                $findings = array_merge($findings, $this->checkInvoiceBalances());

                return $findings;
            });
        });
    }

    /**
     * Check (c), split per spec §9/§17.1 into two independent findings:
     *
     * 1. **document_balance** — the invoice's stored `balance` (document
     *    currency) must equal `total - Σ(non-void payments' amount)`. Pure
     *    document-currency arithmetic; FX never enters into it.
     * 2. **base_ar_reconciliation** — the base-currency AR the invoice
     *    contributed to the ledger (its own P1/P1fx debit plus its
     *    payments' P2/P2fx credits/debits, ALL netted straight from
     *    journal_lines — reversals included, so a voided payment's
     *    primary+reversal pair cancels to zero automatically, without this
     *    method needing to special-case void payments on the ledger side)
     *    must equal `base_total - Σ(non-void payments' base_ar_relief)`.
     *    Scoping the ledger side to account 1100 ONLY (never 4950/5950) is
     *    what makes this tolerant of FX gain/loss — the plug lives on a
     *    completely different account, so it structurally cannot perturb
     *    this sum, whether the invoice is domestic (no plug ever posts) or
     *    foreign (a plug posts on every payment that isn't an exact-rate
     *    settlement). A FULLY PAID invoice additionally must reconcile to
     *    EXACTLY zero — the settlement-rounding property (spec §17.1) —
     *    checked explicitly rather than only implicitly, so a corruption
     *    that keeps the ledger and the payments table mutually consistent
     *    but both wrong (e.g. every payment's base_ar_relief tampered in
     *    lockstep with its ledger line) still gets caught.
     *
     * Only POSTED invoices participate (see POSTED_INVOICE_STATUSES); a
     * draft has nothing posted yet, and a void invoice's own entry has
     * already been reversed to net zero, so neither balance/base_total is
     * meaningful to check against the ledger.
     *
     * @return list<array{check:string, detail:string}>
     */
    private function checkInvoiceBalances(): array
    {
        $findings = [];

        $invoices = Invoice::query()
            ->whereIn('status', self::POSTED_INVOICE_STATUSES)
            ->get(['id', 'number', 'status', 'total', 'balance', 'base_total', 'contact_id', 'is_opening']);

        if ($invoices->isEmpty()) {
            return $findings;
        }

        // Σ(amount)/Σ(base_ar_relief) per invoice, non-void payments only —
        // the business-level "what should still be outstanding" side of
        // both checks. Grouped in one query rather than per-invoice, since
        // this runs against every posted invoice on every company on every
        // nightly run.
        $paymentTotals = InvoicePayment::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->where('status', '!=', 'void')
            ->selectRaw('invoice_id, SUM(amount) AS paid, SUM(base_ar_relief) AS base_relief')
            ->groupBy('invoice_id')
            ->get()
            ->keyBy('invoice_id');

        $actualBaseAr = $this->actualLedgerBaseArByInvoice();

        foreach ($invoices as $invoice) {
            $totals = $paymentTotals->get($invoice->id);
            $paidAmount = (int) ($totals->paid ?? 0);
            $paidBaseAr = (int) ($totals->base_relief ?? 0);

            $expectedBalance = $invoice->total - $paidAmount;
            if ($invoice->balance !== $expectedBalance) {
                $findings[] = [
                    'check' => 'document_balance',
                    'detail' => "Invoice {$this->invoiceLabel($invoice)} balance {$invoice->balance} does not equal total {$invoice->total} minus non-void payments {$paidAmount} (expected {$expectedBalance})",
                ];
            }

            $expectedBaseAr = $invoice->base_total - $paidBaseAr;

            if ($invoice->status === InvoiceStatus::Paid && $expectedBaseAr !== 0) {
                // The settlement-rounding invariant itself (spec §17.1),
                // independent of whether the ledger happens to agree — this
                // is what catches a corruption that tampers the payments
                // table and the ledger in lockstep. Purely business-level
                // (invoice.base_total vs. its own payments' base_ar_relief),
                // so it applies identically whether or not the invoice
                // is_opening — no ledger attribution needed here at all.
                $findings[] = [
                    'check' => 'base_ar_reconciliation',
                    'detail' => "Invoice {$this->invoiceLabel($invoice)} is fully paid but its base-currency AR does not settle to exactly zero (base_total {$invoice->base_total} minus non-void payments' base_ar_relief {$paidBaseAr} = {$expectedBaseAr})",
                ];
            } elseif (! $invoice->is_opening) {
                // Per-invoice ledger tie-out — valid ONLY for a normal
                // invoice, which is individually source-attributable
                // (source_type='invoice'/'invoice_payment', source_id=this
                // invoice's own id). An is_opening invoice has no entry of
                // its own to look up this way — see
                // actualLedgerBaseArByInvoice()'s docblock — those are
                // reconciled in aggregate by checkOpeningArReconciliation()
                // below instead, never here.
                $ledgerBaseAr = $actualBaseAr[$invoice->id] ?? 0;
                if ($expectedBaseAr !== $ledgerBaseAr) {
                    $findings[] = [
                        'check' => 'base_ar_reconciliation',
                        'detail' => "Invoice {$this->invoiceLabel($invoice)} base-currency AR expected {$expectedBaseAr} (base_total minus non-void payments' base_ar_relief) but the ledger shows {$ledgerBaseAr}",
                    ];
                }
            }
        }

        $findings = array_merge($findings, $this->checkOpeningArReconciliation(
            $invoices->where('is_opening', true), $paymentTotals, $actualBaseAr
        ));

        return $findings;
    }

    /**
     * The LEDGER-side of the base-AR tie-out for NORMAL (non-opening)
     * invoices, PLUS the payment-attributable contribution for opening
     * invoices: for every receivable-subtype account, net (debit - credit)
     * every journal_line whose entry's source is either the invoice itself
     * (source_type 'invoice') or one of its payments (source_type
     * 'invoice_payment'), summed by owning invoice_id. Reversal entries are
     * NOT filtered out — they carry the SAME source_type/source_id as what
     * they reverse (spec §4 iron rule), so a voided payment's primary Cr AR
     * and its reversal Dr AR both land in the same sum and net to zero
     * automatically, exactly matching the "non-void payments" filter used on
     * the business-level (payments-table) side above. FX gain/loss lines
     * never appear here at all — they post to 4950/5950, not to any
     * receivable account — which is the entire mechanism by which this
     * tie-out tolerates FX plugs without any FX-specific branching.
     *
     * Two grouped queries (never one per invoice) so this stays cheap
     * regardless of how many invoices/payments a company has accumulated.
     *
     * Opening invoices (is_opening=true) NEVER get an "own entry"
     * contribution here — OpeningBalanceService::createOpeningInvoice()
     * deliberately never calls LedgerService for one (Task 6): the AR was
     * already posted once, by the ORIGINAL opening_balance_run's P8b
     * line(s), keyed by contact_id — NOT by any invoice id, and potentially
     * split across SEVERAL journal_lines for the same contact when that
     * contact has more than one opening invoice in a single run (a customer
     * with two old unpaid invoices at cutover is a realistic, UI-permitted
     * case — neither OpeningBalanceService nor StoreOpeningBalanceRequest
     * dedups customer_lines by contact). Because a specific opening-AR
     * ledger line can't be matched 1:1 back to a specific is_opening invoice
     * in that case, this method does NOT attempt to guess a split — the
     * caller (checkInvoiceBalances) instead reconciles opening invoices at
     * the CONTACT level via checkOpeningArReconciliation() below, which
     * sums both the expected and ledger sides over the same contact scope.
     * The $byPaymentEntry half of this method IS still valid, and used, for
     * opening invoices — a payment always links to exactly one invoice_id
     * via invoice_payments, regardless of is_opening.
     *
     * @return array<int, int> invoice_id => net base AR contributed by that invoice's own entry (normal invoices) + its payments (all invoices)
     */
    private function actualLedgerBaseArByInvoice(): array
    {
        $receivableAccountIds = $this->receivableAccountIds();

        if ($receivableAccountIds->isEmpty()) {
            // No AR account exists at all on this company — every posted
            // invoice above will fail its own expected-vs-ledger comparison
            // (expected is never 0 for a real invoice), which is the correct
            // failure signal; nothing to sum here.
            return [];
        }

        // Own P1/P1fx entry, keyed directly by source_id (= invoice_id).
        // Opening invoices never appear here — see docblock above.
        $byOwnEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $receivableAccountIds)
            ->where('journal_entries.source_type', 'invoice')
            ->selectRaw('journal_entries.source_id AS invoice_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->groupBy('journal_entries.source_id')
            ->pluck('net', 'invoice_id');

        // Payments' P2/P2fx entries, keyed by payment_id first (that's what
        // source_id is on an invoice_payment entry), then re-aggregated up
        // to invoice_id via the invoice_payments table itself. Genuinely
        // invoice-attributable regardless of is_opening.
        $byPaymentEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $receivableAccountIds)
            ->where('journal_entries.source_type', 'invoice_payment')
            ->selectRaw('journal_entries.source_id AS payment_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->groupBy('journal_entries.source_id')
            ->pluck('net', 'payment_id');

        $paymentInvoiceIds = InvoicePayment::query()->pluck('invoice_id', 'id');

        $totals = [];
        foreach ($byOwnEntry as $invoiceId => $net) {
            $totals[(int) $invoiceId] = ($totals[(int) $invoiceId] ?? 0) + (int) $net;
        }
        foreach ($byPaymentEntry as $paymentId => $net) {
            $invoiceId = $paymentInvoiceIds->get($paymentId);
            if ($invoiceId === null) {
                // A payment-sourced AR line whose invoice_payments row is
                // gone — orphan_entries (already registered for
                // 'invoice_payment' above) already reports this; skip it
                // here rather than attributing it to nothing.
                continue;
            }
            $totals[(int) $invoiceId] = ($totals[(int) $invoiceId] ?? 0) + (int) $net;
        }

        return $totals;
    }

    /**
     * base_ar_reconciliation for OPENING invoices, reconciled at the
     * CONTACT level rather than per-invoice — see actualLedgerBaseArByInvoice()'s
     * docblock for why per-invoice attribution isn't possible: the ledger's
     * opening AR debit line(s) carry only contact_id, never invoice_id or
     * opening_document_id, so when one contact has SEVERAL opening invoices
     * in a single run there is no way to split that shared ledger
     * contribution back across them individually. Instead, both the
     * expected side and the ledger side are summed over the SAME set (all
     * of a contact's currently-posted opening invoices), which correctly
     * handles N opening invoices per contact:
     *
     * - Expected side: Σ over the contact's opening invoices of
     *   (base_total − Σ that invoice's own non-void payments'
     *   base_ar_relief) — the same per-invoice expected-outstanding formula
     *   the normal-invoice path uses, just summed across the group.
     * - Ledger side: (Σ this contact's opening_balance_run AR debit lines,
     *   from the run entry(ies) — reversal lines from a voided re-run carry
     *   the SAME contact_id, per LedgerService::reverse(), so they net to
     *   zero automatically within this same sum) PLUS (Σ
     *   $actualBaseAr[invoice_id] for each of the contact's opening
     *   invoices — which, for an opening invoice, holds ONLY its
     *   payment-attributable net, since actualLedgerBaseArByInvoice() never
     *   gives one an "own entry" contribution).
     *
     * A mismatch produces ONE finding per contact (not per invoice), naming
     * every opening invoice in the group.
     *
     * Disjoint from the normal-invoice per-invoice path by construction:
     * this only ever sums source_type='opening_balance_run' lines (scoped
     * by contact_id) and source_type='invoice_payment' lines belonging to
     * invoices in THIS group (scoped by invoice_id via invoice_payments) —
     * a contact's normal invoices are reconciled entirely through
     * source_type='invoice'/'invoice_payment' lines keyed by THEIR OWN
     * invoice_id in the checkInvoiceBalances() loop above, which this
     * method never reads from or writes findings about.
     *
     * @param  Collection<int, Invoice>  $openingInvoices  already filtered to is_opening=true, POSTED_INVOICE_STATUSES
     * @param  Collection<int, object>  $paymentTotals  invoice_id => {paid, base_relief}, non-void payments only
     * @param  array<int, int>  $actualBaseAr  invoice_id => net ledger AR (payment-attributable only, for opening invoices)
     * @return list<array{check:string, detail:string}>
     */
    private function checkOpeningArReconciliation(Collection $openingInvoices, Collection $paymentTotals, array $actualBaseAr): array
    {
        if ($openingInvoices->isEmpty()) {
            return [];
        }

        $findings = [];
        $byContact = $openingInvoices->groupBy('contact_id');

        $receivableAccountIds = $this->receivableAccountIds();
        $runArByContact = $receivableAccountIds->isEmpty() ? collect() : JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $receivableAccountIds)
            ->where('journal_entries.source_type', 'opening_balance_run')
            ->whereIn('journal_lines.contact_id', $byContact->keys())
            ->selectRaw('journal_lines.contact_id AS contact_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->groupBy('journal_lines.contact_id')
            ->pluck('net', 'contact_id');

        foreach ($byContact as $contactId => $contactInvoices) {
            $expected = 0;
            $ledger = (int) ($runArByContact->get($contactId) ?? 0);

            foreach ($contactInvoices as $invoice) {
                $totals = $paymentTotals->get($invoice->id);
                $paidBaseAr = (int) ($totals->base_relief ?? 0);
                $expected += $invoice->base_total - $paidBaseAr;
                $ledger += $actualBaseAr[$invoice->id] ?? 0;
            }

            if ($expected !== $ledger) {
                $labels = $contactInvoices->map(fn (Invoice $invoice) => $this->invoiceLabel($invoice))->implode(', ');
                $findings[] = [
                    'check' => 'base_ar_reconciliation',
                    'detail' => "Contact {$contactId}'s opening invoices ({$labels}) base-currency AR expected {$expected} (\u{03a3} base_total minus non-void payments' base_ar_relief) but the ledger shows {$ledger}",
                ];
            }
        }

        return $findings;
    }

    /** Every receivable-subtype account id for this company — shared scope for the ledger-side AR queries above. */
    private function receivableAccountIds(): Collection
    {
        return Account::query()->where('subtype', AccountSubtype::Receivable->value)->pluck('id');
    }

    private function invoiceLabel(Invoice $invoice): string
    {
        return ($invoice->number ?? "#{$invoice->id}")." (id {$invoice->id})";
    }
}
