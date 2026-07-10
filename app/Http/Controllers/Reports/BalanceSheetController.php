<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\BalanceSheetReport;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceSheetController extends Controller
{
    public function show(Request $request, BalanceSheetReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/BalanceSheet', [
            'report' => $report->build($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Section, Code, Name, Amount — money rendered as exact
     * decimal strings via Money::minorToDecimalString (integer math only,
     * never number_format()/float division), mirroring
     * TrialBalanceController/ProfitAndLossController exactly. Retained
     * Earnings and Current Year Earnings each get their own row (Code left
     * blank, same convention ProfitAndLossController uses for its Total
     * rows) since neither is a single ledger account.
     */
    public function exportCsv(Request $request, BalanceSheetReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->build($asOf);

        $audit->log('report.exported', after: ['report' => 'balance_sheet', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Section', 'Code', 'Name', 'Amount']);

            $this->writeSection($handle, 'Assets', $data['assets']);
            $this->writeTotalRow($handle, 'Assets', 'Total Assets', $data['assets']['total']);

            $this->writeSection($handle, 'Liabilities', $data['liabilities']);
            $this->writeTotalRow($handle, 'Liabilities', 'Total Liabilities', $data['liabilities']['total']);

            $this->writeSection($handle, 'Equity', $data['equity']);
            fputcsv($handle, ['Equity', '', 'Retained Earnings', Money::minorToDecimalString($data['equity']['retained_earnings'])]);
            fputcsv($handle, ['Equity', '', 'Current Year Earnings', Money::minorToDecimalString($data['equity']['current_year_earnings'])]);
            $this->writeTotalRow($handle, 'Equity', 'Total Equity', $data['equity']['total']);

            $this->writeTotalRow($handle, '', 'Total Liabilities & Equity', $data['total_liabilities_and_equity']);

            fclose($handle);
        }, "balance-sheet-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  resource  $handle
     * @param  array{rows: list<array{account_id:int, code:string, name:string, amount:int}>}  $section
     */
    private function writeSection($handle, string $label, array $section): void
    {
        foreach ($section['rows'] as $row) {
            fputcsv($handle, [$label, $row['code'], $row['name'], Money::minorToDecimalString($row['amount'])]);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeTotalRow($handle, string $section, string $label, int $amount): void
    {
        fputcsv($handle, [$section, '', $label, Money::minorToDecimalString($amount)]);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
