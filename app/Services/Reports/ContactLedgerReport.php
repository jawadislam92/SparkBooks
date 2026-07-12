<?php

namespace App\Services\Reports;

use App\Enums\EntryRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalLine;
use App\Models\OpeningDocument;
use Carbon\CarbonImmutable;

/**
 * Customer/vendor balance + receivables/payables report library (Phase 7
 * Task 6, spec §7.11/§11): Customer/Vendor Balance Summary + Detail, Open
 * Invoices / Unpaid Bills, Collections, and Customer/Vendor Statements.
 *
 * THE SHARED ENGINE (why one class, nine public methods): every one of these
 * reports is a different SLICE of the exact same per-document, per-contact
 * reconstruction AgingReport already does (see its class docblock for the
 * full reasoning this mirrors verbatim — contact attribution, the opening-
 * document own-total exception, void-drops-off, base-currency correctness).
 * The one structural difference from AgingReport: that report immediately
 * collapses every document down into ONE aging bucket per contact and
 * throws the per-transaction detail away. These reports need that detail —
 * a running balance, a due date, an original vs. outstanding split — so
 * `ledger()` below keeps BOTH the per-document view (`documents`, for Open
 * Invoices/Unpaid Bills/Collections) and a per-contact chronological
 * MOVEMENT list (`movements_by_contact`, for Balance Detail/Statements),
 * built in a single pass over the same underlying journal_lines queries.
 *
 * FX CORRECTNESS (load-bearing, spec's own explicit gate): every amount
 * here — original, outstanding, and every individual movement — comes from
 * journal_lines (already base-currency by construction: LedgerService only
 * ever posts base-currency minor units, per PROJECT_MAP §9), never from
 * Invoice::total/balance (document-currency; wrong currency entirely for an
 * FX invoice) or even Invoice::base_total for the OUTSTANDING figure (that
 * column is a point-in-time snapshot at send time, not the current ledger
 * position). base_total IS used, deliberately, for the DISPLAY-only
 * "original amount" column — it's the base-currency figure the document was
 * originally sent for, which is exactly what "original" means; it is never
 * used for tie-out math.
 *
 * CREDIT NOTES: the brief for this task mentions "credit notes" as a third
 * AR-affecting transaction type alongside invoices/payments. This codebase
 * has no credit-note feature (no model, no migration, no posting rule) as
 * of Phase 7 — grep confirms zero hits. AgingReport, the primary mirror this
 * task is told to follow, has exactly the same gap and handles it the same
 * way: it isn't handled, because it doesn't exist yet. Every transaction
 * type this class actually emits (invoice/bill, payment, opening) mirrors a
 * REAL posting rule; the day a credit-note feature is built, its own
 * journal_lines source_type joins into `ledger()` the same way payments do.
 *
 * CONTROL-ACCOUNT TIE-OUT: control_balance in every return shape below comes
 * from LedgerBalances::cumulativeAsOf on the 1100 (AR) or 2000 (AP) account
 * — the SAME figure AgingReport's grand_total already ties to. Summary's
 * grand_total, Open Invoices'/Unpaid Bills' total_outstanding, and a
 * Statement's closing_balance are all provably the same sum (every one is
 * built from the identical per-document net-contribution loop below), so if
 * one ties, they all do.
 */
class ContactLedgerReport
{
    public function __construct(private LedgerBalances $ledger) {}

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    /** @return array{as_of:string, direction:string, control_account:?array, control_balance:int, contacts:list<array{contact_id:int,name:string,balance:int}>, grand_total:int, ties_to_control:bool, difference:int} */
    public function customerBalanceSummary(CarbonImmutable $asOf): array
    {
        return $this->summary($this->ledger($asOf, 'ar'));
    }

    /** @return array{as_of:string, direction:string, control_account:?array, control_balance:int, contacts:list<array{contact_id:int,name:string,balance:int}>, grand_total:int, ties_to_control:bool, difference:int} */
    public function vendorBalanceSummary(CarbonImmutable $asOf): array
    {
        return $this->summary($this->ledger($asOf, 'ap'));
    }

    /** @return array{as_of:string, direction:string, contact:?array, transactions:list<array>, balance:int} */
    public function customerBalanceDetail(CarbonImmutable $asOf, int $contactId): array
    {
        return $this->detail($this->ledger($asOf, 'ar'), $contactId);
    }

    /** @return array{as_of:string, direction:string, contact:?array, transactions:list<array>, balance:int} */
    public function vendorBalanceDetail(CarbonImmutable $asOf, int $contactId): array
    {
        return $this->detail($this->ledger($asOf, 'ap'), $contactId);
    }

    /** @return array{as_of:string, direction:string, control_account:?array, control_balance:int, documents:list<array>, total_outstanding:int, ties_to_control:bool, difference:int} */
    public function openInvoices(CarbonImmutable $asOf): array
    {
        return $this->openDocuments($this->ledger($asOf, 'ar'));
    }

    /** @return array{as_of:string, direction:string, control_account:?array, control_balance:int, documents:list<array>, total_outstanding:int, ties_to_control:bool, difference:int} */
    public function unpaidBills(CarbonImmutable $asOf): array
    {
        return $this->openDocuments($this->ledger($asOf, 'ap'));
    }

    /**
     * Overdue AR grouped by customer (aged past each invoice's own due
     * date, strictly — due exactly today is not yet overdue, mirroring
     * AgingReport::bucketFor / DashboardController's isOverdue predicate).
     *
     * @return array{as_of:string, contacts:list<array{contact_id:int,name:string,email:?string,phone:?string,documents:list<array>,total:int}>, grand_total:int}
     */
    public function collections(CarbonImmutable $asOf): array
    {
        $built = $this->ledger($asOf, 'ar');

        $byContact = [];
        foreach ($built['documents'] as $doc) {
            if ($doc['due_date'] === null) {
                continue;
            }
            $dueDate = CarbonImmutable::parse($doc['due_date']);
            if ($dueDate->gte($asOf)) {
                continue;
            }

            $daysOverdue = (int) $dueDate->diffInDays($asOf);
            $contactId = $doc['contact_id'];

            if (! isset($byContact[$contactId])) {
                $contact = $built['contacts'][$contactId] ?? null;
                $byContact[$contactId] = [
                    'contact_id' => $contactId,
                    'name' => $contact['name'] ?? "Contact #{$contactId}",
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'documents' => [],
                    'total' => 0,
                ];
            }

            $byContact[$contactId]['documents'][] = [
                'doc_id' => $doc['doc_id'],
                'reference' => $doc['reference'],
                'due_date' => $doc['due_date'],
                'days_overdue' => $daysOverdue,
                'outstanding' => $doc['outstanding'],
            ];
            $byContact[$contactId]['total'] += $doc['outstanding'];
        }

        $contacts = array_values($byContact);
        usort($contacts, fn (array $a, array $b): int => [$a['name'], $a['contact_id']] <=> [$b['name'], $b['contact_id']]);

        return [
            'as_of' => $asOf->toDateString(),
            'contacts' => $contacts,
            'grand_total' => array_sum(array_column($contacts, 'total')),
        ];
    }

    /** @return array{from:string, to:string, direction:string, contact:?array, opening_balance:int, transactions:list<array>, closing_balance:int} */
    public function customerStatement(CarbonImmutable $from, CarbonImmutable $to, int $contactId): array
    {
        return $this->statement($this->ledger($to, 'ar'), $from, $to, $contactId);
    }

    /** @return array{from:string, to:string, direction:string, contact:?array, opening_balance:int, transactions:list<array>, closing_balance:int} */
    public function vendorStatement(CarbonImmutable $from, CarbonImmutable $to, int $contactId): array
    {
        return $this->statement($this->ledger($to, 'ap'), $from, $to, $contactId);
    }

    // -----------------------------------------------------------------
    // Shape builders (thin — the actual reconstruction lives in ledger())
    // -----------------------------------------------------------------

    private function summary(array $built): array
    {
        $contacts = [];
        foreach ($built['movements_by_contact'] as $contactId => $movements) {
            $balance = array_sum(array_column($movements, 'amount'));
            if ($balance === 0) {
                // Mirrors AgingReport: a contact whose net position is
                // exactly zero (every document paid off / voided) drops off
                // the summary entirely rather than showing a $0 row.
                continue;
            }
            $contact = $built['contacts'][$contactId] ?? null;
            $contacts[] = [
                'contact_id' => $contactId,
                'name' => $contact['name'] ?? "Contact #{$contactId}",
                'balance' => $balance,
            ];
        }

        usort($contacts, fn (array $a, array $b): int => [$a['name'], $a['contact_id']] <=> [$b['name'], $b['contact_id']]);

        $grandTotal = array_sum(array_column($contacts, 'balance'));

        return [
            'as_of' => $built['as_of'],
            'direction' => $built['direction'],
            'control_account' => $built['control_account'],
            'control_balance' => $built['control_balance'],
            'contacts' => $contacts,
            'grand_total' => $grandTotal,
            'ties_to_control' => $grandTotal === $built['control_balance'],
            'difference' => $grandTotal - $built['control_balance'],
        ];
    }

    private function detail(array $built, int $contactId): array
    {
        $movements = $built['movements_by_contact'][$contactId] ?? [];
        usort($movements, fn (array $a, array $b): int => [$a['date'], $a['seq']] <=> [$b['date'], $b['seq']]);

        $running = 0;
        $transactions = [];
        foreach ($movements as $movement) {
            $running += $movement['amount'];
            $transactions[] = [
                'date' => $movement['date'],
                'type' => $movement['type'],
                'reference' => $movement['reference'],
                'doc_id' => $movement['doc_id'],
                'amount' => $movement['amount'],
                'running_balance' => $running,
            ];
        }

        $contactInfo = $built['contacts'][$contactId] ?? null;

        return [
            'as_of' => $built['as_of'],
            'direction' => $built['direction'],
            'contact' => $contactInfo !== null ? ['id' => $contactId, 'name' => $contactInfo['name']] : null,
            'transactions' => $transactions,
            'balance' => $running,
        ];
    }

    private function openDocuments(array $built): array
    {
        $documents = $built['documents'];
        usort($documents, fn (array $a, array $b): int => [$a['contact_name'], $a['contact_id'], $a['doc_id']] <=> [$b['contact_name'], $b['contact_id'], $b['doc_id']]);

        $totalOutstanding = array_sum(array_column($documents, 'outstanding'));

        return [
            'as_of' => $built['as_of'],
            'direction' => $built['direction'],
            'control_account' => $built['control_account'],
            'control_balance' => $built['control_balance'],
            'documents' => $documents,
            'total_outstanding' => $totalOutstanding,
            'ties_to_control' => $totalOutstanding === $built['control_balance'],
            'difference' => $totalOutstanding - $built['control_balance'],
        ];
    }

    private function statement(array $built, CarbonImmutable $from, CarbonImmutable $to, int $contactId): array
    {
        $movements = $built['movements_by_contact'][$contactId] ?? [];
        usort($movements, fn (array $a, array $b): int => [$a['date'], $a['seq']] <=> [$b['date'], $b['seq']]);

        $opening = 0;
        $periodMovements = [];
        foreach ($movements as $movement) {
            $date = CarbonImmutable::parse($movement['date']);
            if ($date->lt($from)) {
                $opening += $movement['amount'];
            } elseif ($date->lte($to)) {
                $periodMovements[] = $movement;
            }
            // Movements dated after $to can't occur — $built was itself
            // constructed with asOf === $to, so every movement is already
            // <= $to by construction; this branch is unreachable but kept
            // as an explicit, safe no-op rather than a silent assumption.
        }

        $running = $opening;
        $transactions = [];
        foreach ($periodMovements as $movement) {
            $running += $movement['amount'];
            $transactions[] = [
                'date' => $movement['date'],
                'type' => $movement['type'],
                'reference' => $movement['reference'],
                'doc_id' => $movement['doc_id'],
                'amount' => $movement['amount'],
                'running_balance' => $running,
            ];
        }

        $contactInfo = $built['contacts'][$contactId] ?? null;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'direction' => $built['direction'],
            'contact' => $contactInfo !== null ? [
                'id' => $contactId,
                'name' => $contactInfo['name'],
                'email' => $contactInfo['email'] ?? null,
                'phone' => $contactInfo['phone'] ?? null,
            ] : null,
            'opening_balance' => $opening,
            'transactions' => $transactions,
            'closing_balance' => $running,
        ];
    }

    // -----------------------------------------------------------------
    // The shared reconstruction engine.
    // -----------------------------------------------------------------

    /**
     * @param  'ar'|'ap'  $direction
     * @return array{
     *     as_of: string,
     *     direction: string,
     *     control_account: ?array{account_id:int, code:string, name:string},
     *     control_balance: int,
     *     documents: list<array>,
     *     movements_by_contact: array<int, list<array{date:string,type:string,doc_id:int,reference:string,amount:int,seq:int}>>,
     *     contacts: array<int, array{name:string, email:?string, phone:?string}>,
     * }
     */
    private function ledger(CarbonImmutable $asOf, string $direction): array
    {
        $asOf = $asOf->startOfDay();
        $accountCode = $direction === 'ar' ? '1100' : '2000';
        $account = Account::query()->where('code', $accountCode)->first();

        if ($account === null) {
            return [
                'as_of' => $asOf->toDateString(),
                'direction' => $direction,
                'control_account' => null,
                'control_balance' => 0,
                'documents' => [],
                'movements_by_contact' => [],
                'contacts' => [],
            ];
        }

        $controlNet = $this->ledger->cumulativeAsOf($asOf, [$account->id])[$account->id]['net'] ?? 0;
        $controlBalance = $direction === 'ar' ? $controlNet : -$controlNet;

        [$docModel, $paymentModel, $documentSourceType, $paymentSourceType, $parentKey, $referenceColumn, $dateColumn] = $direction === 'ar'
            ? [Invoice::class, InvoicePayment::class, 'invoice', 'invoice_payment', 'invoice_id', 'number', 'issue_date']
            : [Bill::class, BillPayment::class, 'bill', 'bill_payment', 'bill_id', 'reference', 'bill_date'];

        // Mirrors AgingReport's identical query exactly — see its class
        // docblock ("THE OPENING-DOCUMENT EXCEPTION") for the full reasoning.
        $openingRunEntryDates = OpeningDocument::query()
            ->join('journal_entries', function ($join) {
                $join->on('journal_entries.source_id', '=', 'opening_documents.opening_balance_run_id')
                    ->whereColumn('journal_entries.company_id', 'opening_documents.company_id')
                    ->where('journal_entries.source_type', '=', 'opening_balance_run')
                    ->where('journal_entries.entry_role', '=', EntryRole::Primary->value);
            })
            ->whereNotNull("opening_documents.{$parentKey}")
            ->pluck('journal_entries.entry_date', "opening_documents.{$parentKey}");

        // Every normal document's OWN entry, netted (debit - credit), keyed
        // by doc id, together with the entry's OWN date (there is always
        // exactly one such entry for a non-void document — see AgingReport's
        // docblock — so MAX() is a safe, ONLY_FULL_GROUP_BY-friendly way to
        // carry it through the same GROUP BY).
        $byOwnEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.source_type', $documentSourceType)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->selectRaw('journal_entries.source_id as doc_id, MAX(journal_entries.entry_date) as entry_date, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->groupBy('journal_entries.source_id')
            ->get()
            ->keyBy('doc_id');

        // Every payment entry, netted (a voided payment's reversal shares
        // the same source_id and nets it back to zero, exactly like
        // AgingReport's byPaymentEntry), keyed by the PAYMENT's own id.
        $byPaymentEntry = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.source_type', $paymentSourceType)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->selectRaw('journal_entries.source_id as payment_id, MAX(journal_entries.entry_date) as entry_date, SUM(journal_lines.debit) - SUM(journal_lines.credit) as net')
            ->groupBy('journal_entries.source_id')
            ->get()
            ->keyBy('payment_id');

        $paymentParentIds = $paymentModel::query()->pluck($parentKey, 'id');

        $paymentsByDoc = [];
        foreach ($byPaymentEntry as $paymentId => $row) {
            $docId = $paymentParentIds->get($paymentId);
            if ($docId === null) {
                // Orphaned payment (parent doc vanished) — an integrity
                // problem IntegrityChecker already flags, not this report's
                // job to guess an attribution for. Mirrors AgingReport.
                continue;
            }
            $paymentsByDoc[(int) $docId][] = [
                'payment_id' => (int) $paymentId,
                'net' => (int) $row->net,
                'date' => $row->entry_date,
            ];
        }

        $documents = $docModel::query()->get();

        $contactIds = $documents->pluck('contact_id')->unique()->values();
        $contactRows = Contact::query()->whereIn('id', $contactIds)->get(['id', 'name', 'email', 'phone']);
        $contacts = [];
        foreach ($contactRows as $contactRow) {
            $contacts[(int) $contactRow->id] = [
                'name' => $contactRow->name,
                'email' => $contactRow->email,
                'phone' => $contactRow->phone,
            ];
        }

        $documentRows = [];
        $movementsByContact = [];
        $seq = 0;

        foreach ($documents as $doc) {
            if ($doc->is_opening && $doc->status?->value === 'void') {
                continue;
            }

            $docId = (int) $doc->id;
            $contactId = (int) $doc->contact_id;

            if ($doc->is_opening) {
                $runEntryDate = $openingRunEntryDates->get($docId);
                $ownEffective = $runEntryDate !== null && CarbonImmutable::parse($runEntryDate)->lte($asOf);
                $ownNet = $ownEffective ? ($direction === 'ar' ? (int) $doc->total : -(int) $doc->total) : 0;
                $ownDate = $runEntryDate !== null ? CarbonImmutable::parse($runEntryDate)->toDateString() : null;
            } else {
                $ownRow = $byOwnEntry->get($docId);
                // A non-opening document's own entry nets to exactly zero
                // only when it has been fully reversed (its void's reversal
                // shares the same source_id, per spec §4, so the GROUP BY
                // above merges original + reversal into one zero net) — a
                // real, unvoided invoice/bill is never posted for zero
                // (InvoiceHasNoValue rejects it before it can be sent). So
                // net===0 here means "voided", and it drops off entirely,
                // exactly like AgingReport's own voided-document handling.
                $ownEffective = $ownRow !== null && (int) $ownRow->net !== 0;
                $ownNet = $ownEffective ? (int) $ownRow->net : 0;
                $ownDate = $ownEffective ? CarbonImmutable::parse($ownRow->entry_date)->toDateString() : null;
            }

            if (! $ownEffective) {
                // Not yet posted to the ledger by $asOf (draft, or sent
                // after $asOf, or an opening doc whose run isn't effective
                // yet) — no ledger impact at all at this cutoff, so it
                // contributes nothing and generates no dated row (a real
                // payment can never predate its own invoice/bill, so there
                // is nothing else that could contribute here either).
                continue;
            }

            $paymentRows = $paymentsByDoc[$docId] ?? [];
            $paymentsNet = 0;
            foreach ($paymentRows as $p) {
                $paymentsNet += $p['net'];
            }

            $totalNet = $ownNet + $paymentsNet;
            $outstanding = $direction === 'ar' ? $totalNet : -$totalNet;

            $reference = $doc->{$referenceColumn} ?? ($direction === 'ar' ? "Invoice #{$docId}" : "Bill #{$docId}");
            $contactName = $contacts[$contactId]['name'] ?? "Contact #{$contactId}";
            $type = $doc->is_opening ? 'opening' : ($direction === 'ar' ? 'invoice' : 'bill');

            $movementsByContact[$contactId][] = [
                'date' => $ownDate,
                'type' => $type,
                'doc_id' => $docId,
                'reference' => $reference,
                'amount' => $direction === 'ar' ? $ownNet : -$ownNet,
                'seq' => $seq++,
            ];

            foreach ($paymentRows as $p) {
                if ($p['net'] === 0) {
                    // A voided payment's reversal nets its own contribution
                    // back to zero — no movement row, mirrors a voided
                    // document being dropped entirely elsewhere.
                    continue;
                }
                $movementsByContact[$contactId][] = [
                    'date' => CarbonImmutable::parse($p['date'])->toDateString(),
                    'type' => 'payment',
                    'doc_id' => $docId,
                    'reference' => $reference,
                    'amount' => $direction === 'ar' ? $p['net'] : -$p['net'],
                    'seq' => $seq++,
                ];
            }

            if ($outstanding !== 0) {
                $originalAmount = $direction === 'ar' ? (int) ($doc->base_total ?? $doc->total) : (int) $doc->total;
                $documentRows[] = [
                    'contact_id' => $contactId,
                    'contact_name' => $contactName,
                    'doc_id' => $docId,
                    'type' => $type === 'opening' ? ($direction === 'ar' ? 'invoice' : 'bill') : $type,
                    'reference' => $reference,
                    'date' => $doc->{$dateColumn}?->toDateString(),
                    'due_date' => $doc->due_date?->toDateString(),
                    'currency_code' => $direction === 'ar' ? $doc->currency_code : null,
                    'status' => $doc->status->value,
                    'original' => $originalAmount,
                    'outstanding' => $outstanding,
                ];
            }
        }

        return [
            'as_of' => $asOf->toDateString(),
            'direction' => $direction,
            'control_account' => ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'control_balance' => $controlBalance,
            'documents' => $documentRows,
            'movements_by_contact' => $movementsByContact,
            'contacts' => $contacts,
        ];
    }
}
