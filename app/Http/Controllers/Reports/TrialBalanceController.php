<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\TrialBalanceReport;
use App\Support\AuditLogger;
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
     * decimal strings via integer math only (see minorToDecimalString),
     * never number_format()/float division, so an odd-paisa amount like
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
                    $this->minorToDecimalString($row['debit']),
                    $this->minorToDecimalString($row['credit']),
                ]);
            }

            fputcsv($handle, [
                '',
                'Totals',
                $this->minorToDecimalString($data['total_debit']),
                $this->minorToDecimalString($data['total_credit']),
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

    /**
     * Converts an integer minor-unit amount into an exact decimal string
     * using only integer division/modulo — never float math. Trial Balance
     * rows are never negative, but this is implemented sign-safe regardless.
     */
    private function minorToDecimalString(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
