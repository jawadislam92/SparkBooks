<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Reports\AccountQuickReport;
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

class AccountQuickReportController extends Controller
{
    /**
     * The drill-down TARGET every figure in P&L / Balance Sheet / Trial
     * Balance links into via ?account_id=&from=&to= (also reachable
     * directly through its own account picker + period fields). Unlike
     * GeneralLedgerController, `account_id` has no "all accounts" option —
     * this screen is always exactly one account (or none yet selected, in
     * which case `report` is null and the page shows just the picker).
     */
    public function show(Request $request, AccountQuickReport $report, CompanyContext $context): Response
    {
        [$from, $to, $accountId] = $this->resolveParams($request, $context);

        $accounts = Account::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name])
            ->values();

        return Inertia::render('Reports/AccountQuickReport', [
            'report' => $accountId !== null ? $report->build($from, $to, $accountId) : null,
            'accounts' => $accounts,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: an Opening balance row, every listed line (with the
     * originating sub-account shown, in case this is a rolled-up parent
     * view), and a Closing balance row — money rendered as exact decimal
     * strings via Money::minorToDecimalString (integer math only, never
     * number_format()/float division), mirroring GeneralLedgerController
     * exactly. `account_id` is required here — there's nothing sensible to
     * export without one.
     */
    public function exportCsv(Request $request, AccountQuickReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->resolvePeriod($validated, $context);
        $data = $report->build($from, $to, (int) $validated['account_id']);

        $audit->log('report.exported', after: [
            'report' => 'account_quickreport',
            'account_id' => $data['account_id'],
            'from' => $data['from'],
            'to' => $data['to'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Sub-account', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit', 'Balance']);

            fputcsv($handle, [
                '', '', '', 'Opening balance', '', '', '',
                Money::minorToDecimalString($data['opening_balance']),
            ]);

            foreach ($data['lines'] as $line) {
                fputcsv($handle, [
                    "{$line['account_code']} — {$line['account_name']}",
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
                '', '', '', 'Closing balance', '', '', '',
                Money::minorToDecimalString($data['closing_balance']),
            ]);

            fclose($handle);
        }, "account-quickreport-{$data['code']}-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Accepts the drill-down query params other reports link in with:
     * ?account_id={id}&from=&to=. Missing from/to fall back independently
     * (mirrors GeneralLedgerController's own default), so a link that only
     * passes `to` (e.g. Balance Sheet's as-of date) still gets a sensible
     * current-fiscal-year-to-date range rather than an error. account_id
     * itself is optional here (unlike exportCsv) — the picker screen is a
     * valid state with nothing selected yet.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int}
     */
    private function resolveParams(Request $request, CompanyContext $context): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->resolvePeriod($validated, $context);

        return [$from, $to, isset($validated['account_id']) ? (int) $validated['account_id'] : null];
    }

    /**
     * @param  array{from?: string, to?: string}  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(array $validated, CompanyContext $context): array
    {
        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $period->currentFiscalYearStart($today);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $today;

        return [$from, $to];
    }
}
