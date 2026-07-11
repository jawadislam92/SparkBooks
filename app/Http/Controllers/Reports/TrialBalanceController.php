<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\TrialBalanceReport;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrialBalanceController extends Controller
{
    public function show(Request $request, TrialBalanceReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/TrialBalance', [
            'report' => $report->build($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Code, Name, Debit, Credit — money rendered as exact
     * decimal strings via Money::minorToDecimalString (integer math only,
     * never number_format()/float division), so an odd-paisa amount like
     * Rs 33.33 can never round-trip as "33.329999999999995".
     */
    public function exportCsv(Request $request, TrialBalanceReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->build($asOf);

        $audit->log('report.exported', after: ['report' => 'trial_balance', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Code', 'Name', 'Debit', 'Credit']);

            foreach ($data['rows'] as $row) {
                fputcsv($handle, [
                    $row['code'],
                    $row['name'],
                    Money::minorToDecimalString($row['debit']),
                    Money::minorToDecimalString($row['credit']),
                ]);
            }

            fputcsv($handle, [
                '',
                'Totals',
                Money::minorToDecimalString($data['total_debit']),
                Money::minorToDecimalString($data['total_credit']),
            ]);

            fclose($handle);
        }, "trial-balance-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
