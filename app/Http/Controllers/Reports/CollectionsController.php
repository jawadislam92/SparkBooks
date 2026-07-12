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
 * Collections report (Phase 7 Task 6): overdue AR grouped by customer, with
 * days-overdue + contact phone/email for chasing. See
 * ContactLedgerReport::collections for the overdue predicate (strictly past
 * due — due exactly today is never overdue).
 */
class CollectionsController extends Controller
{
    public function show(Request $request, ContactLedgerReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/Collections', [
            'report' => $report->collections($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /** Streamed CSV: Customer, Email, Phone, Invoice, Due date, Days overdue, Outstanding. */
    public function exportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->collections($asOf);

        $audit->log('report.exported', after: ['report' => 'collections', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Customer', 'Email', 'Phone', 'Invoice', 'Due date', 'Days overdue', 'Outstanding']);

            foreach ($data['contacts'] as $contact) {
                foreach ($contact['documents'] as $doc) {
                    fputcsv($handle, [
                        $contact['name'],
                        $contact['email'],
                        $contact['phone'],
                        $doc['reference'],
                        $doc['due_date'],
                        $doc['days_overdue'],
                        Money::minorToDecimalString($doc['outstanding']),
                    ]);
                }
            }

            fputcsv($handle, ['', '', '', '', '', 'Total', Money::minorToDecimalString($data['grand_total'])]);

            fclose($handle);
        }, "collections-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
