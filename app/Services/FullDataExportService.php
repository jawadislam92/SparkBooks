<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Money;
use RuntimeException;
use ZipArchive;

/**
 * The Full Data Export (spec §11, Phase 7 Task 7) — the portability/escape-
 * hatch sibling of AccountantPackageService's reports bundle: a zip of raw
 * per-table CSVs for the current company (accounts, contacts, items, journal
 * entries+lines, invoices+their payments, bills+their payments — "whatever
 * the core tables are", per this task's brief, after inspecting the schema).
 *
 * Every query below relies on the SAME company scoping every other service
 * in this app already uses — each model's BelongsToCompany global scope
 * (see App\Models\Concerns\BelongsToCompany) filters to
 * App\Support\CompanyContext's current company automatically; nothing here
 * calls withoutGlobalScope or adds an explicit ->where('company_id', ...).
 *
 * This class performs NO accounting math of its own — every money column is
 * emitted verbatim as its stored integer minor-unit value (never
 * recomputed), plus a human decimal rendering via
 * Money::minorToDecimalString for readability, exactly like every report's
 * own CSV export already does.
 */
class FullDataExportService
{
    /**
     * Table names this export covers, in the order written to the zip —
     * also what a caller should audit-log as "what was exported" (§17.8).
     *
     * @var list<string>
     */
    public const TABLES = [
        'accounts',
        'contacts',
        'items',
        'journal_entries',
        'journal_lines',
        'invoices',
        'invoice_payments',
        'bills',
        'bill_payments',
    ];

    public function build(): string
    {
        $entries = [
            'accounts.csv' => $this->accountsCsv(),
            'contacts.csv' => $this->contactsCsv(),
            'items.csv' => $this->itemsCsv(),
            'journal_entries.csv' => $this->journalEntriesCsv(),
            'journal_lines.csv' => $this->journalLinesCsv(),
            'invoices.csv' => $this->invoicesCsv(),
            'invoice_payments.csv' => $this->invoicePaymentsCsv(),
            'bills.csv' => $this->billsCsv(),
            'bill_payments.csv' => $this->billPaymentsCsv(),
        ];

        return $this->writeZip($entries);
    }

    /**
     * Builds the zip at a fresh temp path. Every failure branch AFTER
     * tempnam() creates the file cleans that file up before throwing — same
     * "no temp-file leak on success OR exception" convention as
     * AccountantPackageService::writeZip.
     *
     * @param  array<string, string>  $entries
     */
    private function writeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sparkbooks_full_export_');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temp file for the full data export zip.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the full data export zip archive.');
            }

            foreach ($entries as $name => $csv) {
                if ($zip->addFromString($name, $csv) === false) {
                    $zip->close();
                    throw new RuntimeException("Unable to write the '{$name}' entry to the full data export zip.");
                }
            }

            $zip->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $path;
    }

    private function accountsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Code', 'Name', 'Type', 'Subtype', 'Parent ID', 'Is Postable', 'Is System', 'Is Archived', 'Description']);

        foreach (Account::query()->orderBy('code')->get() as $account) {
            fputcsv($handle, [
                $account->id,
                $account->code,
                $account->name,
                $account->type->value,
                $account->subtype->value,
                $account->parent_id,
                $account->is_postable ? '1' : '0',
                $account->is_system ? '1' : '0',
                $account->is_archived ? '1' : '0',
                $account->description,
            ]);
        }

        return $this->contents($handle);
    }

    private function contactsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Name', 'Type', 'Email', 'Phone', 'Billing Address', 'Tax Number', 'Notes', 'Is Archived']);

        foreach (Contact::query()->orderBy('id')->get() as $contact) {
            fputcsv($handle, [
                $contact->id,
                $contact->name,
                $contact->type->value,
                $contact->email,
                $contact->phone,
                $contact->billing_address,
                $contact->tax_number,
                $contact->notes,
                $contact->is_archived ? '1' : '0',
            ]);
        }

        return $this->contents($handle);
    }

    private function itemsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Name', 'Description', 'Unit Price (Minor)', 'Unit Price', 'Default Income Account Code', 'Default Tax Rate Name', 'Is Archived']);

        foreach (Item::query()->with(['incomeAccount', 'taxRate'])->orderBy('id')->get() as $item) {
            fputcsv($handle, [
                $item->id,
                $item->name,
                $item->description,
                $item->unit_price,
                Money::minorToDecimalString($item->unit_price),
                $item->incomeAccount?->code,
                $item->taxRate?->name,
                $item->is_archived ? '1' : '0',
            ]);
        }

        return $this->contents($handle);
    }

    private function journalEntriesCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Entry Number', 'Entry Date', 'Memo', 'Source Type', 'Source ID', 'Entry Role', 'Reverses Entry ID', 'Created By User ID', 'Posted At']);

        foreach (JournalEntry::query()->orderBy('id')->get() as $entry) {
            fputcsv($handle, [
                $entry->id,
                $entry->entry_number,
                $entry->entry_date->toDateString(),
                $entry->memo,
                $entry->source_type,
                $entry->source_id,
                $entry->entry_role->value,
                $entry->reverses_entry_id,
                $entry->created_by,
                optional($entry->posted_at)->toIso8601String(),
            ]);
        }

        return $this->contents($handle);
    }

    private function journalLinesCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Journal Entry ID', 'Account ID', 'Account Code', 'Debit (Minor)', 'Debit', 'Credit (Minor)', 'Credit', 'Memo', 'Contact ID']);

        foreach (JournalLine::query()->with('account')->orderBy('id')->get() as $line) {
            fputcsv($handle, [
                $line->id,
                $line->journal_entry_id,
                $line->account_id,
                $line->account?->code,
                $line->debit,
                Money::minorToDecimalString($line->debit),
                $line->credit,
                Money::minorToDecimalString($line->credit),
                $line->memo,
                $line->contact_id,
            ]);
        }

        return $this->contents($handle);
    }

    private function invoicesCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'ID', 'Number', 'Contact ID', 'Contact Name', 'Status', 'Issue Date', 'Due Date', 'Currency', 'FX Rate',
            'Subtotal (Minor)', 'Subtotal', 'Tax Total (Minor)', 'Tax Total', 'Total (Minor)', 'Total',
            'Amount Paid (Minor)', 'Amount Paid', 'Balance (Minor)', 'Balance',
            'Base Total (Minor)', 'Base Total', 'Is Opening',
        ]);

        foreach (Invoice::query()->with('contact')->orderBy('id')->get() as $invoice) {
            fputcsv($handle, [
                $invoice->id,
                $invoice->number,
                $invoice->contact_id,
                $invoice->contact?->name,
                $invoice->status->value,
                optional($invoice->issue_date)->toDateString(),
                optional($invoice->due_date)->toDateString(),
                $invoice->currency_code,
                $invoice->fx_rate,
                $invoice->subtotal,
                Money::minorToDecimalString($invoice->subtotal),
                $invoice->tax_total,
                Money::minorToDecimalString($invoice->tax_total),
                $invoice->total,
                Money::minorToDecimalString($invoice->total),
                $invoice->amount_paid,
                Money::minorToDecimalString($invoice->amount_paid),
                $invoice->balance,
                Money::minorToDecimalString($invoice->balance),
                $invoice->base_total,
                Money::minorToDecimalString($invoice->base_total),
                $invoice->is_opening ? '1' : '0',
            ]);
        }

        return $this->contents($handle);
    }

    private function invoicePaymentsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Invoice ID', 'Payment Date', 'Amount (Minor)', 'Amount', 'Base Amount (Minor)', 'Base Amount', 'Bank Account ID', 'FX Rate', 'Status', 'Memo']);

        foreach (InvoicePayment::query()->orderBy('id')->get() as $payment) {
            fputcsv($handle, [
                $payment->id,
                $payment->invoice_id,
                $payment->payment_date->toDateString(),
                $payment->amount,
                Money::minorToDecimalString($payment->amount),
                $payment->base_amount,
                Money::minorToDecimalString($payment->base_amount),
                $payment->bank_account_id,
                $payment->fx_rate,
                $payment->status,
                $payment->memo,
            ]);
        }

        return $this->contents($handle);
    }

    private function billsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'ID', 'Reference', 'Contact ID', 'Contact Name', 'Status', 'Bill Date', 'Due Date',
            'Subtotal (Minor)', 'Subtotal', 'Tax Total (Minor)', 'Tax Total', 'Total (Minor)', 'Total',
            'Amount Paid (Minor)', 'Amount Paid', 'Balance (Minor)', 'Balance', 'Is Opening',
        ]);

        foreach (Bill::query()->with('contact')->orderBy('id')->get() as $bill) {
            fputcsv($handle, [
                $bill->id,
                $bill->reference,
                $bill->contact_id,
                $bill->contact?->name,
                $bill->status->value,
                optional($bill->bill_date)->toDateString(),
                optional($bill->due_date)->toDateString(),
                $bill->subtotal,
                Money::minorToDecimalString($bill->subtotal),
                $bill->tax_total,
                Money::minorToDecimalString($bill->tax_total),
                $bill->total,
                Money::minorToDecimalString($bill->total),
                $bill->amount_paid,
                Money::minorToDecimalString($bill->amount_paid),
                $bill->balance,
                Money::minorToDecimalString($bill->balance),
                $bill->is_opening ? '1' : '0',
            ]);
        }

        return $this->contents($handle);
    }

    private function billPaymentsCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['ID', 'Bill ID', 'Payment Date', 'Amount (Minor)', 'Amount', 'Bank Account ID', 'Status', 'Memo']);

        foreach (BillPayment::query()->orderBy('id')->get() as $payment) {
            fputcsv($handle, [
                $payment->id,
                $payment->bill_id,
                $payment->payment_date->toDateString(),
                $payment->amount,
                Money::minorToDecimalString($payment->amount),
                $payment->bank_account_id,
                $payment->status,
                $payment->memo,
            ]);
        }

        return $this->contents($handle);
    }

    /** @param resource $handle */
    private function contents($handle): string
    {
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
