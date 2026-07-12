<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ContactLedgerReport;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Open Invoices (Phase 7 Task 6): every unpaid/partly-paid invoice, with
 * original + outstanding (base-correct) amounts; total ties to the 1100 AR
 * control account. See ContactLedgerReport for the shared reconstruction.
 */
class OpenInvoicesController extends Controller
{
    public function show(Request $request, ContactLedgerReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/OpenInvoices', [
            'report' => $report->openInvoices($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /** Streamed CSV: Customer, Invoice, Date, Due date, Original, Outstanding. */
    public function exportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->openInvoices($asOf);

        $audit->log('report.exported', after: ['report' => 'open_invoices', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Customer', 'Invoice', 'Date', 'Due date', 'Original', 'Outstanding']);

            foreach ($data['documents'] as $row) {
                fputcsv($handle, [
                    $row['contact_name'],
                    $row['reference'],
                    $row['date'],
                    $row['due_date'],
                    Money::minorToDecimalString($row['original']),
                    Money::minorToDecimalString($row['outstanding']),
                ]);
            }

            fputcsv($handle, ['', '', '', 'Total', '', Money::minorToDecimalString($data['total_outstanding'])]);

            fclose($handle);
        }, "open-invoices-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
