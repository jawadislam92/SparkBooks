<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;

/**
 * A/R Aging + A/P Aging (spec §7.11 — the ONE report whose bucket date comes
 * from the SOURCE DOCUMENT rather than the ledger): outstanding balance per
 * contact, bucketed by how many days past its OWN document's due date the
 * report date (`$asOf`) falls. One shared bucketing engine — `buildReceivables()`
 * and `buildPayables()` are thin wrappers that pick the control account
 * (1100 vs 2000), the source document model (Invoice vs Bill), the payment
 * model (InvoicePayment vs BillPayment), and the debit/credit sign flip.
 *
 * PROJECT_MAP §9 rule 9 ("reports derive from journal_lines, never document
 * totals") still holds for every AMOUNT here — see "THE OPENING-DOCUMENT
 * EXCEPTION" below for the one narrow, deliberate, fully-reasoned departure
 * this report needs to stay correct. This class performs NO ledger writes.
 *
 * WHY THIS CAN'T REUSE LedgerBalances (T1 reviewer note): LedgerBalances is
 * keyed by account_id only — it collapses every contact and every source
 * document on an account into one number. Aging needs BOTH a contact split
 * (rows) AND a source-document split (the due date each dollar is bucketed
 * by), so this class writes its own journal_lines queries, grouped by
 * `journal_entries.source_id` (mirrors IntegrityChecker's
 * actualLedgerBaseArByInvoice()/actualLedgerApByBill() shape exactly, the
 * established pattern for exactly this kind of per-document ledger
 * reconstruction). LedgerBalances::cumulativeAsOf() IS still used, but only
 * once per report, for the single account-level `control_balance` figure the
 * tie-out gate compares against.
 *
 * THE PER-DOCUMENT MODEL: for every non-void invoice/bill, this class
 * reconstructs "how much of the control account's balance is this ONE
 * document's" as:
 *
 *   outstanding(doc) = doc's own original posting (in debit−credit terms)
 *                     + Σ(every payment entry attributed to this doc)
 *
 * flipped to "positive is outstanding" at the end (AR is debit-normal, so
 * no flip; AP is credit-normal, so the sign is inverted). Payments always
 * attribute cleanly: an invoice_payment/bill_payment entry's source_id is
 * the payment's own id, and invoice_payments.invoice_id / bill_payments.
 * bill_id maps that straight back to its parent document — regardless of
 * whether the parent is a normal or an opening document. A voided payment's
 * reversal shares the SAME source_id (spec §4 iron rule) and so nets
 * automatically to zero in the grouped SUM; nothing special-cased.
 *
 * THE OPENING-DOCUMENT EXCEPTION (why "doc's own original posting" isn't
 * always a ledger lookup): a NORMAL invoice/bill posts its own P1/P6 entry
 * sourced `(source_type: 'invoice'|'bill', source_id: <that document's id>)`
 * — a clean 1:1 join. An is_opening invoice/bill never gets one
 * (OpeningBalanceService::buildLinesAndDocuments() docblock: "no ledger
 * posting of its own — the P8b/P8c line already covers it"), and that P8b/
 * P8c line is sourced by the RUN (`opening_balance_run`, `source_id: <run
 * id>`), not by the individual document — so when one contact has two
 * opening documents in the same run (the fbbe763 case this task's brief
 * calls out), there is no column that says which of the run's several
 * same-account, same-contact lines belongs to which document (IntegrityChecker's
 * checkOpeningArReconciliation()/checkOpeningApReconciliation() hit this
 * exact wall and solve it by reconciling at the CONTACT level instead of
 * per-document). Aging needs a per-DOCUMENT split (each doc keeps its own
 * due date), so it resolves the ambiguity differently: an opening document's
 * "own posting" is taken to be its own `total` column (signed for AR/AP)
 * rather than a ledger lookup. This is not a guess — OpeningBalanceService
 * posts EXACTLY one dedicated line per opening document with
 * debit/credit === that document's own `amount`, and `amount` is copied
 * verbatim into the document's `total` — so `total` IS that document's
 * ledger contribution, just not independently queryable once several such
 * lines share a run. Summed across a contact's non-void opening documents,
 * this is provably identical to the run's own contact-level ledger total
 * (each is one dedicated line; at most one run is ever active), so the tie-
 * out gate holds without ever needing to isolate a single line.
 *
 * A voided run (re-run, spec §7.1) reverses ALL of that run's lines and
 * flips ALL its documents to `status: void` in the same transaction. This
 * class drops a void opening document from aging entirely (mirroring
 * "voided doc drops off"), a date-INSENSITIVE rule — safe because
 * OpeningBalanceService::voidPriorRun() dates the reversal on the EXACT SAME
 * `entry_date` as the original run entry (never "today"), so there is no
 * `$asOf` for which the ledger's cumulative view includes the original but
 * excludes the reversal (or vice versa): they are always both-in or both-
 * out together. A NORMAL invoice/bill void needs no such special case at
 * all — its reversal shares the SAME source_id as its own entry, so the
 * grouped ledger SUM nets it to zero automatically, correctly respecting
 * whatever `$asOf` the reversal's own (independently chosen) date falls on.
 *
 * BUCKETS (relative to $asOf, by the document's own due date):
 *   Current   — due_date is null, OR due_date >= $asOf (not yet due).
 *   1–30      — 1  <= days past due <= 30
 *   31–60     — 31 <= days past due <= 60
 *   61–90     — 61 <= days past due <= 90
 *   90+       — days past due >= 91
 *
 * NULL DUE DATE FALLBACK (both invoices.due_date AND bills.due_date are
 * nullable columns — the brief calls out the invoice case, but the bills
 * migration is nullable too): treated as "Current". A document with no
 * established due date has no deadline it could be judged "past" against —
 * bucketing it by issue_date instead would manufacture a false "overdue"
 * signal the business never actually committed to. opening_documents.due_date
 * is NOT NULL (always captured at setup), so this fallback only ever
 * applies to a normal invoice/bill that was posted without one.
 *
 * SIGN CONVENTION: identical spirit to TrialBalanceReport/BalanceSheetReport
 * — every "outstanding" figure is presented positive-is-normal (a customer
 * owing money is a positive AR figure; a bill owed to a vendor is a positive
 * AP figure), with no debit/credit language anywhere in this class's output.
 */
class AgingReport
{
    public const BUCKET_KEYS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    public const BUCKET_LABELS = [
        'current' => 'Current',
        'days_1_30' => '1–30 days',
        'days_31_60' => '31–60 days',
        'days_61_90' => '61–90 days',
        'days_90_plus' => '90+ days',
    ];

    public function __construct(private LedgerBalances $ledger) {}

    /**
     * @return array{
     *     as_of: string,
     *     direction: 'ar',
     *     control_account: ?array{account_id:int, code:string, name:string},
     *     control_balance: int,
     *     buckets: list<string>,
     *     bucket_labels: array<string, string>,
     *     contacts: list<array{contact_id:int, name:string, buckets: array<string,int>, total:int}>,
     *     totals: array<string,int>,
     *     grand_total: int,
     *     ties_to_control: bool,
     *     difference: int,
     * }
     */
    public function buildReceivables(CarbonImmutable $asOf): array
    {
        return $this->build($asOf, 'ar');
    }

    /**
     * @return array{
     *     as_of: string,
     *     direction: 'ap',
     *     control_account: ?array{account_id:int, code:string, name:string},
     *     control_balance: int,
     *     buckets: list<string>,
     *     bucket_labels: array<string, string>,
     *     contacts: list<array{contact_id:int, name:string, buckets: array<string,int>, total:int}>,
     *     totals: array<string,int>,
     *     grand_total: int,
     *     ties_to_control: bool,
     *     difference: int,
     * }
     */
    public function buildPayables(CarbonImmutable $asOf): array
    {
        return $this->build($asOf, 'ap');
    }

    /**
     * @param  'ar'|'ap'  $direction
     * @return array{
     *     as_of: string,
     *     direction: string,
     *     control_account: ?array{account_id:int, code:string, name:string},
     *     control_balance: int,
     *     buckets: list<string>,
     *     bucket_labels: array<string, string>,
     *     contacts: list<array{contact_id:int, name:string, buckets: array<string,int>, total:int}>,
     *     totals: array<string,int>,
     *     grand_total: int,
     *     ties_to_control: bool,
     *     difference: int,
     * }
     */
    private function build(CarbonImmutable $asOf, string $direction): array
    {
        $asOf = $asOf->startOfDay();
        $accountCode = $direction === 'ar' ? '1100' : '2000';
        $account = Account::query()->where('code', $accountCode)->first();

        if ($account === null) {
            return $this->emptyReport($asOf, $direction, null, 0);
        }

        $controlNet = $this->ledger->cumulativeAsOf($asOf, [$account->id])[$account->id]['net'] ?? 0;
        $controlBalance = $direction === 'ar' ? $controlNet : -$controlNet;

        [$docModel, $paymentModel, $documentSourceType, $paymentSourceType, $parentKey] = $direction === 'ar'
            ? [Invoice::class, InvoicePayment::class, 'invoice', 'invoice_payment', 'invoice_id']
            : [Bill::class, BillPayment::class, 'bill', 'bill_payment', 'bill_id'];

        // Every normal document's OWN entry, netted (debit − credit), keyed
        // by the document id (journal_entries.source_id when
        // source_type is 'invoice'/'bill'). Opening documents never appear
        // here — see class docblock's OPENING-DOCUMENT EXCEPTION.
        $byOwnEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.source_type', $documentSourceType)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->selectRaw('journal_entries.source_id as doc_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->groupBy('journal_entries.source_id')
            ->pluck('net', 'doc_id');

        // Every payment entry, netted, keyed by the PAYMENT's own id — needs
        // one more hop (below) to re-key by parent document id.
        $byPaymentEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.source_type', $paymentSourceType)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->selectRaw('journal_entries.source_id as payment_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->groupBy('journal_entries.source_id')
            ->pluck('net', 'payment_id');

        $paymentParentIds = $paymentModel::query()->pluck($parentKey, 'id');

        $paymentNetByDoc = [];
        foreach ($byPaymentEntry as $paymentId => $net) {
            $docId = $paymentParentIds->get($paymentId);
            if ($docId === null) {
                // Orphaned payment row (its parent doc vanished) — a
                // ledger-integrity problem IntegrityChecker already flags,
                // not aging's job to guess an attribution for.
                continue;
            }
            $docId = (int) $docId;
            $paymentNetByDoc[$docId] = ($paymentNetByDoc[$docId] ?? 0) + (int) $net;
        }

        $documents = $docModel::query()->get(['id', 'contact_id', 'due_date', 'total', 'is_opening', 'status']);

        $contactIds = $documents->pluck('contact_id')->unique()->values();
        $contactNames = Contact::query()->whereIn('id', $contactIds)->pluck('name', 'id');

        /** @var array<int, array{contact_id:int, name:string, buckets: array<string,int>, total:int}> $byContact */
        $byContact = [];

        foreach ($documents as $doc) {
            if ($doc->is_opening && $doc->status?->value === 'void') {
                // Whole-run reversal already zeroed this out ledger-side —
                // see OPENING-DOCUMENT EXCEPTION in the class docblock for
                // why this static status check is safe regardless of $asOf.
                continue;
            }

            $net = $doc->is_opening
                ? ($direction === 'ar' ? (int) $doc->total : -(int) $doc->total)
                : (int) ($byOwnEntry[$doc->id] ?? 0);
            $net += $paymentNetByDoc[$doc->id] ?? 0;

            $outstanding = $direction === 'ar' ? $net : -$net;

            if ($outstanding === 0) {
                // Fully paid, or a normal doc whose void reversal nets its
                // own entry back to zero — either way, it drops off.
                continue;
            }

            $dueDate = $doc->due_date !== null ? CarbonImmutable::parse($doc->due_date->toDateString()) : null;
            $bucket = $this->bucketFor($dueDate, $asOf);

            $contactId = (int) $doc->contact_id;
            if (! isset($byContact[$contactId])) {
                $byContact[$contactId] = [
                    'contact_id' => $contactId,
                    'name' => $contactNames->get($contactId) ?? "Contact #{$contactId}",
                    'buckets' => array_fill_keys(self::BUCKET_KEYS, 0),
                    'total' => 0,
                ];
            }
            $byContact[$contactId]['buckets'][$bucket] += $outstanding;
            $byContact[$contactId]['total'] += $outstanding;
        }

        $contacts = array_values($byContact);
        usort($contacts, fn (array $a, array $b): int => [$a['name'], $a['contact_id']] <=> [$b['name'], $b['contact_id']]);

        $totals = array_fill_keys(self::BUCKET_KEYS, 0);
        $grandTotal = 0;
        foreach ($contacts as $contact) {
            foreach (self::BUCKET_KEYS as $key) {
                $totals[$key] += $contact['buckets'][$key];
            }
            $grandTotal += $contact['total'];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'direction' => $direction,
            'control_account' => ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'control_balance' => $controlBalance,
            'buckets' => self::BUCKET_KEYS,
            'bucket_labels' => self::BUCKET_LABELS,
            'contacts' => $contacts,
            'totals' => $totals,
            'grand_total' => $grandTotal,
            'ties_to_control' => $grandTotal === $controlBalance,
            'difference' => $grandTotal - $controlBalance,
        ];
    }

    /**
     * Bucket assignment, boundary-tested exactly (§12.9): due exactly on
     * $asOf is 0 days past due -> Current (not yet due). 1-30 / 31-60 /
     * 61-90 are each inclusive on both ends; 91+ is the open-ended tail.
     * Only ever called with $dueDate < $asOf past this method's own
     * null/>= guard, so diffInDays() (absolute by default) is safe to use
     * without a signed-diff footgun.
     */
    private function bucketFor(?CarbonImmutable $dueDate, CarbonImmutable $asOf): string
    {
        if ($dueDate === null || $dueDate->gte($asOf)) {
            return 'current';
        }

        $daysPastDue = $dueDate->diffInDays($asOf);

        return match (true) {
            $daysPastDue <= 30 => 'days_1_30',
            $daysPastDue <= 60 => 'days_31_60',
            $daysPastDue <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }

    /** @return array{as_of:string, direction:string, control_account:null, control_balance:int, buckets:list<string>, bucket_labels:array<string,string>, contacts:list<mixed>, totals:array<string,int>, grand_total:int, ties_to_control:bool, difference:int} */
    private function emptyReport(CarbonImmutable $asOf, string $direction, ?array $controlAccount, int $controlBalance): array
    {
        return [
            'as_of' => $asOf->toDateString(),
            'direction' => $direction,
            'control_account' => $controlAccount,
            'control_balance' => $controlBalance,
            'buckets' => self::BUCKET_KEYS,
            'bucket_labels' => self::BUCKET_LABELS,
            'contacts' => [],
            'totals' => array_fill_keys(self::BUCKET_KEYS, 0),
            'grand_total' => 0,
            'ties_to_control' => $controlBalance === 0,
            'difference' => 0 - $controlBalance,
        ];
    }
}
