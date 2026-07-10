<?php

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Company;
use App\Models\OpeningDocument;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill for Phase 4 Task 7 — the exact AP-side mirror of
 * ReconcileOpeningInvoices (Phase 3 Task 6): companies whose opening
 * payables were set up before this task existed have only bare
 * opening_documents rows (kind=payable) with no payable representation —
 * OpeningBalanceService::apply() now creates an is_opening Bill per vendor
 * opening payable going forward (see its createOpeningBill), but this
 * command reconciles the ones that predate that change.
 *
 * For each OPEN payable opening_document with no linked bill yet: creates an
 * is_opening Bill (status Received, balance/total = amount) and links it
 * back via opening_documents.bill_id — the exact same shape
 * OpeningBalanceService now builds going forward, MINUS any ledger posting:
 * the AP for these was already posted by the original opening_balance_run's
 * P8c entry, so this command must NEVER post a second one. Voided
 * opening_documents and customer (receivable) ones are left untouched — a
 * voided payable was never payable, and receivables are out of scope for
 * this task (Phase 3 Task 6/ReconcileOpeningInvoices is the symmetric
 * feature).
 *
 * Idempotent: only opening_documents with bill_id IS NULL are considered, so
 * a re-run creates nothing new for a document this command (or the
 * now-updated OpeningBalanceService) already linked.
 *
 * NOT run automatically anywhere — mirrors ReconcileOpeningInvoices, run
 * once against affected company data by hand.
 */
class ReconcileOpeningBills extends Command
{
    protected $signature = 'sparkbooks:reconcile-opening-bills';

    protected $description = "Create is_opening bills for pre-Task-7 opening payables that don't have one yet (idempotent)";

    public function handle(CompanyContext $context): int
    {
        $totalCreated = 0;

        foreach (Company::query()->get() as $company) {
            $createdForCompany = $context->runFor($company, function () use ($company) {
                $documents = OpeningDocument::query()
                    ->where('kind', 'payable')
                    ->where('status', 'open')
                    ->whereNull('bill_id')
                    ->get();

                $created = 0;
                foreach ($documents as $document) {
                    $linked = DB::transaction(function () use ($document) {
                        // Re-check under the lock: a concurrent run (or a
                        // stale row in this collection) may have already
                        // linked this document.
                        $locked = OpeningDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                        if ($locked->bill_id !== null) {
                            return false;
                        }

                        $bill = Bill::query()->create([
                            'contact_id' => $locked->contact_id,
                            'reference' => $locked->reference,
                            'status' => BillStatus::Received->value,
                            'bill_date' => $locked->original_date->toDateString(),
                            'due_date' => $locked->due_date->toDateString(),
                            'subtotal' => $locked->amount,
                            'tax_total' => 0,
                            'total' => $locked->amount,
                            'amount_paid' => 0,
                            'balance' => $locked->amount,
                            'is_opening' => true,
                            'received_at' => now(),
                        ]);

                        $locked->update(['bill_id' => $bill->id]);

                        return true;
                    });

                    $created += $linked ? 1 : 0;
                }

                $this->info("Company {$company->id} ({$company->name}): created {$created} opening bill(s).");

                return $created;
            });

            $totalCreated += $createdForCompany;
        }

        $this->info("Done. Created {$totalCreated} opening bill(s) total.");

        return self::SUCCESS;
    }
}
