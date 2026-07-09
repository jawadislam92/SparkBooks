<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\OpeningDocument;
use App\Services\DocumentNumberService;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill for Phase 3 Task 6 (spec §6 P8b): companies whose opening
 * receivables were set up before this task existed have only bare
 * opening_documents rows (kind=receivable) with no payable representation —
 * OpeningBalanceService::apply() now creates an is_opening Invoice per
 * customer opening receivable going forward (see its createOpeningInvoice),
 * but this command reconciles the ones that predate that change.
 *
 * For each OPEN receivable opening_document with no linked invoice yet:
 * creates an is_opening Invoice (status Sent, balance/total = amount,
 * OB-series number) and links it back via opening_documents.invoice_id —
 * the exact same shape OpeningBalanceService now builds going forward, MINUS
 * any ledger posting: the AR for these was already posted by the original
 * opening_balance_run's P8b entry, so this command must NEVER post a second
 * one. Voided opening_documents and vendor (payable) ones are left
 * untouched — a voided receivable was never payable, and vendor payables
 * are out of scope for this task (Phase 4 bills are the symmetric feature).
 *
 * Idempotent: only opening_documents with invoice_id IS NULL are considered,
 * so a re-run creates nothing new for a document this command (or the
 * now-updated OpeningBalanceService) already linked.
 *
 * NOT run automatically anywhere — the Phase 3 exit-verification task runs
 * this once against the dev/SparkingAsia data by hand.
 */
class ReconcileOpeningInvoices extends Command
{
    protected $signature = 'sparkbooks:reconcile-opening-invoices';

    protected $description = "Create is_opening invoices for pre-Task-6 opening receivables that don't have one yet (idempotent)";

    public function handle(CompanyContext $context, DocumentNumberService $numbers): int
    {
        $totalCreated = 0;

        foreach (Company::query()->get() as $company) {
            $createdForCompany = $context->runFor($company, function () use ($company, $numbers) {
                $documents = OpeningDocument::query()
                    ->where('kind', 'receivable')
                    ->where('status', 'open')
                    ->whereNull('invoice_id')
                    ->get();

                $created = 0;
                foreach ($documents as $document) {
                    $linked = DB::transaction(function () use ($document, $company, $numbers) {
                        // Re-check under the lock: a concurrent run (or a
                        // stale row in this collection) may have already
                        // linked this document.
                        $locked = OpeningDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                        if ($locked->invoice_id !== null) {
                            return false;
                        }

                        $invoice = Invoice::query()->create([
                            'contact_id' => $locked->contact_id,
                            'number' => $numbers->next('OB', 'OB'),
                            'status' => InvoiceStatus::Sent->value,
                            'issue_date' => $locked->original_date->toDateString(),
                            'due_date' => $locked->due_date->toDateString(),
                            'currency_code' => $company->base_currency,
                            'fx_rate' => 1,
                            'subtotal' => $locked->amount,
                            'tax_total' => 0,
                            'total' => $locked->amount,
                            'amount_paid' => 0,
                            'balance' => $locked->amount,
                            'base_subtotal' => $locked->amount,
                            'base_tax_total' => 0,
                            'base_total' => $locked->amount,
                            'is_opening' => true,
                            'sent_at' => now(),
                        ]);

                        $locked->update(['invoice_id' => $invoice->id]);

                        return true;
                    });

                    $created += $linked ? 1 : 0;
                }

                $this->info("Company {$company->id} ({$company->name}): created {$created} opening invoice(s).");

                return $created;
            });

            $totalCreated += $createdForCompany;
        }

        $this->info("Done. Created {$totalCreated} opening invoice(s) total.");

        return self::SUCCESS;
    }
}
