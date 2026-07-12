<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\CashFlowReport;
use App\Services\Reports\ReportPeriod;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statement of Cash Flows (spec seed §2, Phase 7 Task 4). Period shape
 * mirrors ProfitAndLossController exactly (a [from, to] range, defaulting to
 * the current fiscal year to date via ReportPeriod) — a cash-flow statement
 * is inherently a PERIOD report, like the P&L, not an as-of snapshot like the
 * Balance Sheet.
 */
class CashFlowController extends Controller
{
    public function show(Request $request, CashFlowReport $report, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Reports/CashFlow', [
            'report' => $report->build($from, $to),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Section, Code, Name, Amount — money rendered as exact
     * decimal strings via Money::minorToDecimalString (integer math only,
     * never number_format()/float division), mirroring
     * BalanceSheetController/ProfitAndLossController exactly.
     */
    public function exportCsv(Request $request, CashFlowReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request, $context);
        $data = $report->build($from, $to);

        $audit->log('report.exported', after: [
            'report' => 'cash_flow',
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Section', 'Code', 'Name', 'Amount']);

            fputcsv($handle, ['Operating', '', 'Net income', Money::minorToDecimalString($data['operating']['net_income'])]);
            $this->writeRows($handle, 'Operating', $data['operating']['add_backs']['rows']);
            fputcsv($handle, ['Operating', '', 'Foreign exchange (gain)/loss — reclassified below', Money::minorToDecimalString($data['operating']['fx_reclass'])]);
            $this->writeRows($handle, 'Operating', $data['operating']['working_capital']['rows']);
            fputcsv($handle, ['Operating', '', 'Net cash from operating activities', Money::minorToDecimalString($data['operating']['total'])]);

            $this->writeRows($handle, 'Investing', $data['investing']['rows']);
            fputcsv($handle, ['Investing', '', 'Net cash from investing activities', Money::minorToDecimalString($data['investing']['total'])]);

            $this->writeRows($handle, 'Financing', $data['financing']['rows']);
            fputcsv($handle, ['Financing', '', 'Net cash from financing activities', Money::minorToDecimalString($data['financing']['total'])]);

            fputcsv($handle, ['', '', 'Effect of exchange rate changes on cash', Money::minorToDecimalString($data['fx_on_cash'])]);
            fputcsv($handle, ['', '', 'Net change in cash', Money::minorToDecimalString($data['net_change_in_cash'])]);
            fputcsv($handle, ['', '', 'Cash at beginning of period', Money::minorToDecimalString($data['opening_cash'])]);
            fputcsv($handle, ['', '', 'Cash at end of period', Money::minorToDecimalString($data['closing_cash'])]);

            fclose($handle);
        }, "cash-flow-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  resource  $handle
     * @param  list<array{account_id:int, code:string, name:string, amount:int}>  $rows
     */
    private function writeRows($handle, string $section, array $rows): void
    {
        foreach ($rows as $row) {
            fputcsv($handle, [$section, $row['code'], $row['name'], Money::minorToDecimalString($row['amount'])]);
        }
    }

    /**
     * Default: current fiscal year to date — identical convention to
     * ProfitAndLossController::resolveRange.
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
