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
 * Unpaid Bills — the AP-side mirror of OpenInvoicesController, tied to the
 * 2000 AP control account.
 */
class UnpaidBillsController extends Controller
{
    public function show(Request $request, ContactLedgerReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/UnpaidBills', [
            'report' => $report->unpaidBills($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /** Streamed CSV: Vendor, Bill, Date, Due date, Original, Outstanding. */
    public function exportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->unpaidBills($asOf);

        $audit->log('report.exported', after: ['report' => 'unpaid_bills', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Vendor', 'Bill', 'Date', 'Due date', 'Original', 'Outstanding']);

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
        }, "unpaid-bills-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
