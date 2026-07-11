<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ProfitAndLossReport;
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

class ProfitAndLossController extends Controller
{
    public function show(Request $request, ProfitAndLossReport $report, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Reports/ProfitAndLoss', [
            'report' => $report->build($from, $to),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Section, Code, Name, one column per month bucket, Total
     * — money rendered as exact decimal strings via
     * Money::minorToDecimalString (integer math only, never
     * number_format()/float division, and sign-safe so a loss period's
     * negative Net Profit renders as "-700.00" rather than corrupting).
     */
    public function exportCsv(Request $request, ProfitAndLossReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request, $context);
        $data = $report->build($from, $to);

        $audit->log('report.exported', after: [
            'report' => 'profit_and_loss',
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');
            $monthLabels = array_map(fn (array $m): string => $m['label'], $data['months']);

            fputcsv($handle, ['Section', 'Code', 'Name', ...$monthLabels, 'Total']);

            $this->writeSectionRows($handle, 'Income', $data['income']);
            $this->writeTotalRow($handle, 'Total Income', $data['income']['total_by_month'], $data['income']['total']);

            $this->writeSectionRows($handle, 'Expenses', $data['expenses']);
            $this->writeTotalRow($handle, 'Total Expenses', $data['expenses']['total_by_month'], $data['expenses']['total']);

            $this->writeTotalRow($handle, 'Net Profit', $data['net_profit_by_month'], $data['net_profit']);

            fclose($handle);
        }, "profit-and-loss-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  resource  $handle
     * @param  array{rows: list<array{account_id: int, code: string, name: string, amount: int, months: list<int>}>, total: int, total_by_month: list<int>}  $section
     */
    private function writeSectionRows($handle, string $label, array $section): void
    {
        foreach ($section['rows'] as $row) {
            fputcsv($handle, [
                $label,
                $row['code'],
                $row['name'],
                ...array_map(fn (int $v): string => Money::minorToDecimalString($v), $row['months']),
                Money::minorToDecimalString($row['amount']),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<int>  $monthAmounts
     */
    private function writeTotalRow($handle, string $label, array $monthAmounts, int $total): void
    {
        fputcsv($handle, [
            '',
            '',
            $label,
            ...array_map(fn (int $v): string => Money::minorToDecimalString($v), $monthAmounts),
            Money::minorToDecimalString($total),
        ]);
    }

    /**
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

        // Default: current fiscal year to date — the range analogue of Trial
        // Balance's "as of today" default, using T1's ReportPeriod for the
        // fiscal-year math (companies.fiscal_year_start_month).
        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        return [$period->currentFiscalYearStart($today), $today];
    }
}
