<?php

namespace App\Services;

use App\Services\Reports\AgingReport;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\CashFlowReport;
use App\Services\Reports\GeneralLedgerReport;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\TrialBalanceReport;
use App\Support\CompanyContext;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * The Accountant Package (spec §11, Phase 6 Task 4) — "one-click Accountant
 * package ... This is also the escape hatch": General Ledger, Trial Balance,
 * P&L, Balance Sheet, A/R aging, A/P aging for a chosen [from, to] period,
 * each as BOTH a CSV and a PDF, bundled into a single zip. Every figure in
 * the package comes straight from the SAME Phase 5 report services every
 * other screen/export already ties to (PROJECT_MAP §9 rule 9 — reports
 * derive from journal_lines, never document totals) — this class performs
 * NO accounting math of its own, it only formats and bundles.
 *
 * PERIOD SHAPE mirrors each report's own natural period param exactly (see
 * each Reports/*Controller for the identical convention this copies):
 * General Ledger and Profit & Loss take the full [from, to] RANGE; Trial
 * Balance, Balance Sheet, and both agings are AS-OF a single date, so they
 * all use $to (matching e.g. BalanceSheetController defaulting its as_of to
 * "today", the end of whatever range the caller is viewing).
 *
 * CSV rendering duplicates each Reports/*Controller::exportCsv's exact
 * column shape and Money::minorToDecimalString formatting (never
 * number_format()/float division) so a package CSV is byte-for-byte the same
 * shape a user would get from that report's own standalone export — the
 * duplication is deliberate (this class writes to an in-memory php://temp
 * buffer for zipping rather than streaming straight to the HTTP response,
 * so it can't literally call the controller methods, which are
 * StreamedResponse-shaped).
 *
 * PDF rendering mirrors InvoicePdf ("THE MIRROR" per this task's brief):
 * dompdf renders a Blade view's own inline CSS (no Tailwind), using the same
 * ink/spark/paper hex palette invoice.blade.php hardcodes, and the same
 * money-formatting convention (InvoicePdf::formatMoney's "CODE 1,234.56"
 * shape) — reused directly rather than reinvented, since every amount here
 * is already in the company's base currency (every Phase 5 report is
 * base-currency-only, PROJECT_MAP §9).
 */
class AccountantPackageService
{
    public function __construct(
        private GeneralLedgerReport $generalLedger,
        private TrialBalanceReport $trialBalance,
        private ProfitAndLossReport $profitAndLoss,
        private BalanceSheetReport $balanceSheet,
        private CashFlowReport $cashFlow,
        private AgingReport $aging,
        private CompanyContext $context,
    ) {}

    /**
     * Builds the full 12-entry zip at a fresh temp path and returns that
     * path. The caller (AccountantPackageController) is responsible for
     * streaming it to the response and deleting it afterwards
     * (`response()->download(...)->deleteFileAfterSend(true)` — "build to a
     * temp path, stream it, clean up" per this task's brief).
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $company = $this->context->get();

        $generalLedgerData = $this->generalLedger->build($from, $to);
        $trialBalanceData = $this->trialBalance->build($to);
        $profitAndLossData = $this->profitAndLoss->build($from, $to);
        $balanceSheetData = $this->balanceSheet->build($to);
        $cashFlowData = $this->cashFlow->build($from, $to);
        $arAgingData = $this->aging->buildReceivables($to);
        $apAgingData = $this->aging->buildPayables($to);

        $periodLabel = $from->toDateString() === $to->toDateString()
            ? $to->toDateString()
            : "{$from->toDateString()} to {$to->toDateString()}";

        $entries = [
            'general-ledger' => [
                'csv' => $this->generalLedgerCsv($generalLedgerData),
                'pdf' => $this->renderPdf('pdf.reports.general-ledger', [
                    'company' => $company,
                    'title' => 'General Ledger',
                    'periodLabel' => $periodLabel,
                    'data' => $generalLedgerData,
                ]),
            ],
            'trial-balance' => [
                'csv' => $this->trialBalanceCsv($trialBalanceData),
                'pdf' => $this->renderPdf('pdf.reports.trial-balance', [
                    'company' => $company,
                    'title' => 'Trial Balance',
                    'periodLabel' => $to->toDateString(),
                    'data' => $trialBalanceData,
                ]),
            ],
            'profit-and-loss' => [
                'csv' => $this->profitAndLossCsv($profitAndLossData),
                'pdf' => $this->renderPdf('pdf.reports.profit-and-loss', [
                    'company' => $company,
                    'title' => 'Profit & Loss',
                    'periodLabel' => $periodLabel,
                    'data' => $profitAndLossData,
                ]),
            ],
            'balance-sheet' => [
                'csv' => $this->balanceSheetCsv($balanceSheetData),
                'pdf' => $this->renderPdf('pdf.reports.balance-sheet', [
                    'company' => $company,
                    'title' => 'Balance Sheet',
                    'periodLabel' => $to->toDateString(),
                    'data' => $balanceSheetData,
                ]),
            ],
            'cash-flow' => [
                'csv' => $this->cashFlowCsv($cashFlowData),
                'pdf' => $this->renderPdf('pdf.reports.cash-flow', [
                    'company' => $company,
                    'title' => 'Statement of Cash Flows',
                    'periodLabel' => $periodLabel,
                    'data' => $cashFlowData,
                ]),
            ],
            'ar-aging' => [
                'csv' => $this->agingCsv($arAgingData),
                'pdf' => $this->renderPdf('pdf.reports.aging', [
                    'company' => $company,
                    'title' => 'A/R Aging',
                    'periodLabel' => $to->toDateString(),
                    'data' => $arAgingData,
                ]),
            ],
            'ap-aging' => [
                'csv' => $this->agingCsv($apAgingData),
                'pdf' => $this->renderPdf('pdf.reports.aging', [
                    'company' => $company,
                    'title' => 'A/P Aging',
                    'periodLabel' => $to->toDateString(),
                    'data' => $apAgingData,
                ]),
            ],
        ];

        return $this->writeZip($entries);
    }

    private function renderPdf(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)->setPaper('a4')->output();
    }

    /**
     * Builds the zip at a fresh temp path. Every failure branch AFTER tempnam()
     * creates the file cleans that file up before throwing — the brief's "no
     * temp-file leak on success OR exception" requirement — so a failed build
     * never leaves an orphaned empty/truncated zip in the system temp dir.
     *
     * @param  array<string, array{csv: string, pdf: string}>  $entries
     */
    private function writeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sparkbooks_accountant_package_');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temp file for the accountant package zip.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the accountant package zip archive.');
            }

            foreach ($entries as $name => $formats) {
                // addFromString returns false on a per-entry write failure —
                // unchecked, that would silently ship the customer a truncated
                // package, so each write is asserted and fails loudly instead.
                if ($zip->addFromString("{$name}.csv", $formats['csv']) === false
                    || $zip->addFromString("{$name}.pdf", $formats['pdf']) === false) {
                    $zip->close();
                    throw new RuntimeException("Unable to write the '{$name}' entries to the accountant package zip.");
                }
            }

            $zip->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $path;
    }

    // -----------------------------------------------------------------
    // CSV builders — one per report, each an exact mirror of that report's
    // own Reports/*Controller::exportCsv column shape and money formatting.
    // -----------------------------------------------------------------

    /** Mirrors GeneralLedgerController::exportCsv with no account/contact filter (always the full "showBalance" shape — the package never filters). */
    private function generalLedgerCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance']);

        foreach ($data['accounts'] as $account) {
            fputcsv($handle, [
                $account['code'], $account['name'], '', '', 'Opening balance', '', '', '',
                Money::minorToDecimalString($account['opening_balance']),
            ]);

            foreach ($account['lines'] as $line) {
                fputcsv($handle, [
                    $account['code'],
                    $account['name'],
                    $line['entry_date'],
                    "Entry #{$line['entry_id']}",
                    $line['source_label'],
                    $line['contact_name'] ?? '',
                    Money::minorToDecimalString($line['debit']),
                    Money::minorToDecimalString($line['credit']),
                    Money::minorToDecimalString($line['running_balance']),
                ]);
            }

            fputcsv($handle, [
                $account['code'], $account['name'], '', '', 'Closing balance', '', '', '',
                Money::minorToDecimalString($account['closing_balance']),
            ]);
        }

        return $this->contents($handle);
    }

    /** Mirrors TrialBalanceController::exportCsv exactly. */
    private function trialBalanceCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');

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
            '', 'Totals',
            Money::minorToDecimalString($data['total_debit']),
            Money::minorToDecimalString($data['total_credit']),
        ]);

        return $this->contents($handle);
    }

    /** Mirrors ProfitAndLossController::exportCsv exactly (Section, Code, Name, one column per month, Total). */
    private function profitAndLossCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        $monthLabels = array_map(fn (array $m): string => $m['label'], $data['months']);

        fputcsv($handle, ['Section', 'Code', 'Name', ...$monthLabels, 'Total']);

        $this->writePnlSection($handle, 'Income', $data['income']);
        $this->writePnlTotalRow($handle, 'Total Income', $data['income']['total_by_month'], $data['income']['total']);

        $this->writePnlSection($handle, 'Expenses', $data['expenses']);
        $this->writePnlTotalRow($handle, 'Total Expenses', $data['expenses']['total_by_month'], $data['expenses']['total']);

        $this->writePnlTotalRow($handle, 'Net Profit', $data['net_profit_by_month'], $data['net_profit']);

        return $this->contents($handle);
    }

    /** @param resource $handle */
    private function writePnlSection($handle, string $label, array $section): void
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
    private function writePnlTotalRow($handle, string $label, array $monthAmounts, int $total): void
    {
        fputcsv($handle, [
            '', '', $label,
            ...array_map(fn (int $v): string => Money::minorToDecimalString($v), $monthAmounts),
            Money::minorToDecimalString($total),
        ]);
    }

    /** Mirrors BalanceSheetController::exportCsv exactly. */
    private function balanceSheetCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Section', 'Code', 'Name', 'Amount']);

        $this->writeBsSection($handle, 'Assets', $data['assets']);
        $this->writeBsTotalRow($handle, 'Assets', 'Total Assets', $data['assets']['total']);

        $this->writeBsSection($handle, 'Liabilities', $data['liabilities']);
        $this->writeBsTotalRow($handle, 'Liabilities', 'Total Liabilities', $data['liabilities']['total']);

        $this->writeBsSection($handle, 'Equity', $data['equity']);
        fputcsv($handle, ['Equity', '', 'Retained Earnings', Money::minorToDecimalString($data['equity']['retained_earnings'])]);
        fputcsv($handle, ['Equity', '', 'Current Year Earnings', Money::minorToDecimalString($data['equity']['current_year_earnings'])]);
        $this->writeBsTotalRow($handle, 'Equity', 'Total Equity', $data['equity']['total']);

        $this->writeBsTotalRow($handle, '', 'Total Liabilities & Equity', $data['total_liabilities_and_equity']);

        return $this->contents($handle);
    }

    /** @param resource $handle */
    private function writeBsSection($handle, string $label, array $section): void
    {
        foreach ($section['rows'] as $row) {
            fputcsv($handle, [$label, $row['code'], $row['name'], Money::minorToDecimalString($row['amount'])]);
        }
    }

    /** @param resource $handle */
    private function writeBsTotalRow($handle, string $section, string $label, int $amount): void
    {
        fputcsv($handle, [$section, '', $label, Money::minorToDecimalString($amount)]);
    }

    /** Mirrors CashFlowController::exportCsv exactly. */
    private function cashFlowCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Section', 'Code', 'Name', 'Amount']);

        fputcsv($handle, ['Operating', '', 'Net income', Money::minorToDecimalString($data['operating']['net_income'])]);
        $this->writeCashFlowRows($handle, 'Operating', $data['operating']['add_backs']['rows']);
        fputcsv($handle, ['Operating', '', 'Foreign exchange (gain)/loss — reclassified below', Money::minorToDecimalString($data['operating']['fx_reclass'])]);
        $this->writeCashFlowRows($handle, 'Operating', $data['operating']['working_capital']['rows']);
        fputcsv($handle, ['Operating', '', 'Net cash from operating activities', Money::minorToDecimalString($data['operating']['total'])]);

        $this->writeCashFlowRows($handle, 'Investing', $data['investing']['rows']);
        fputcsv($handle, ['Investing', '', 'Net cash from investing activities', Money::minorToDecimalString($data['investing']['total'])]);

        $this->writeCashFlowRows($handle, 'Financing', $data['financing']['rows']);
        fputcsv($handle, ['Financing', '', 'Net cash from financing activities', Money::minorToDecimalString($data['financing']['total'])]);

        fputcsv($handle, ['', '', 'Effect of exchange rate changes on cash', Money::minorToDecimalString($data['fx_on_cash'])]);
        fputcsv($handle, ['', '', 'Net change in cash', Money::minorToDecimalString($data['net_change_in_cash'])]);
        fputcsv($handle, ['', '', 'Cash at beginning of period', Money::minorToDecimalString($data['opening_cash'])]);
        fputcsv($handle, ['', '', 'Cash at end of period', Money::minorToDecimalString($data['closing_cash'])]);

        return $this->contents($handle);
    }

    /** @param resource $handle */
    private function writeCashFlowRows($handle, string $section, array $rows): void
    {
        foreach ($rows as $row) {
            fputcsv($handle, [$section, $row['code'], $row['name'], Money::minorToDecimalString($row['amount'])]);
        }
    }

    /** Mirrors ArAgingController/ApAgingController's shared writeCsv exactly — same shape for both directions, only the input $data differs. */
    private function agingCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');

        $bucketLabels = array_map(fn (string $key): string => $data['bucket_labels'][$key], $data['buckets']);
        fputcsv($handle, ['Contact', ...$bucketLabels, 'Total']);

        foreach ($data['contacts'] as $row) {
            $amounts = array_map(fn (string $key): string => Money::minorToDecimalString($row['buckets'][$key]), $data['buckets']);
            fputcsv($handle, [$row['name'], ...$amounts, Money::minorToDecimalString($row['total'])]);
        }

        $totalAmounts = array_map(fn (string $key): string => Money::minorToDecimalString($data['totals'][$key]), $data['buckets']);
        fputcsv($handle, ['Totals', ...$totalAmounts, Money::minorToDecimalString($data['grand_total'])]);

        return $this->contents($handle);
    }

    /** @param resource $handle */
    private function contents($handle): string
    {
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
