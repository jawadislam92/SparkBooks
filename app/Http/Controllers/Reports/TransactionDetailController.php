<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\TransactionDetailReport;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionDetailController extends Controller
{
    public function show(Request $request, TransactionDetailReport $report, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Reports/TransactionDetail', [
            'report' => $report->build($from, $to),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: one row per line, a Subtotal row per account, and a
     * final Grand total row — money rendered as exact decimal strings via
     * Money::minorToDecimalString (integer math only, never
     * number_format()/float division), mirroring GeneralLedgerController/
     * TrialBalanceController exactly.
     */
    public function exportCsv(Request $request, TransactionDetailReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request, $context);
        $data = $report->build($from, $to);

        $audit->log('report.exported', after: [
            'report' => 'transaction_detail',
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance']);

            foreach ($data['accounts'] as $account) {
                foreach ($account['lines'] as $line) {
                    fputcsv($handle, [
                        $account['code'],
                        $account['name'],
                        $line['entry_date'],
                        $line['entry_number_display'] ?? "Entry #{$line['entry_id']}",
                        $line['source_label'],
                        $line['contact_name'] ?? '',
                        Money::minorToDecimalString($line['debit']),
                        Money::minorToDecimalString($line['credit']),
                        Money::minorToDecimalString($line['running_balance']),
                    ]);
                }

                fputcsv($handle, [
                    $account['code'], $account['name'], '', '', 'Subtotal', '',
                    Money::minorToDecimalString($account['subtotal_debit']),
                    Money::minorToDecimalString($account['subtotal_credit']),
                    Money::minorToDecimalString($account['net']),
                ]);
            }

            fputcsv($handle, [
                '', '', '', '', 'Grand total', '',
                Money::minorToDecimalString($data['grand_total_debit']),
                Money::minorToDecimalString($data['grand_total_credit']),
                '',
            ]);

            fclose($handle);
        }, "transaction-detail-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
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

        // Default: current fiscal year to date -- same default every other
        // period-range report (ProfitAndLossController) uses.
        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        return [$period->currentFiscalYearStart($today), $today];
    }
}
