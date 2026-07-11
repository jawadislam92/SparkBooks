<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\TaxSummaryReport;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxSummaryController extends Controller
{
    public function show(Request $request, TaxSummaryReport $report, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Reports/TaxSummary', [
            'report' => $report->build($from, $to),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Line, Amount — money rendered as exact decimal strings
     * via Money::minorToDecimalString (integer math only, sign-safe so a
     * negative adjustments/net figure renders as "-700.00" rather than
     * corrupting) — mirrors TrialBalanceController/ProfitAndLossController.
     */
    public function exportCsv(Request $request, TaxSummaryReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request, $context);
        $data = $report->build($from, $to);

        $audit->log('report.exported', after: [
            'report' => 'tax_summary',
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Line', 'Amount']);
            fputcsv($handle, ['Collected (output tax)', Money::minorToDecimalString($data['collected'])]);
            fputcsv($handle, ['Input tax', Money::minorToDecimalString($data['input'])]);
            fputcsv($handle, ['Tax remittances', Money::minorToDecimalString($data['remittances'])]);
            fputcsv($handle, ['Adjustments', Money::minorToDecimalString($data['adjustments'])]);
            fputcsv($handle, ['Net tax payable', Money::minorToDecimalString($data['net_tax_payable'])]);

            fclose($handle);
        }, "tax-summary-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Mirrors ProfitAndLossController::resolveRange exactly: default to the
     * current fiscal year to date via T1's ReportPeriod.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(Request $request, CompanyContext $context): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        if (isset($validated['from']) && isset($validated['to'])) {
            return [CarbonImmutable::parse($validated['from']), CarbonImmutable::parse($validated['to'])];
        }

        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        return [$period->currentFiscalYearStart($today), $today];
    }
}
