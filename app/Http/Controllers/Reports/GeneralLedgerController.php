<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Contact;
use App\Services\Reports\GeneralLedgerReport;
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

class GeneralLedgerController extends Controller
{
    public function show(Request $request, GeneralLedgerReport $report, CompanyContext $context): Response
    {
        [$from, $to, $accountId, $contactId] = $this->resolveParams($request, $context);

        $accounts = Account::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name])
            ->values();

        return Inertia::render('Reports/GeneralLedger', [
            'report' => $report->build($from, $to, $accountId, $contactId),
            'accounts' => $accounts,
            // Resolved once here (rather than making the React page do a
            // second round-trip) purely so the "Filtered to X only" banner
            // can show a name instead of a bare id -- cheap, single-row
            // lookup, only ever run when a contact drill-down is active.
            'contactName' => $contactId !== null ? Contact::query()->find($contactId)?->name : null,
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: one Opening balance row and one Closing balance row per
     * account, with every listed line in between — money rendered as exact
     * decimal strings via Money::minorToDecimalString (integer math only,
     * never number_format()/float division), mirroring
     * TrialBalanceController/ProfitAndLossController exactly.
     *
     * MIRRORS THE SCREEN under a contact filter: when a contact drill-down is
     * active, the Balance column and the Opening/Closing rows are dropped —
     * those figures are the whole account's balance netted through only this
     * contact's lines, so they'd be misread as the contact's own balance
     * (see GeneralLedgerReport's class docblock / GeneralLedger.tsx). The
     * filtered export is an honest per-contact line list, nothing more.
     */
    public function exportCsv(Request $request, GeneralLedgerReport $report, CompanyContext $context, AuditLogger $audit): StreamedResponse
    {
        [$from, $to, $accountId, $contactId] = $this->resolveParams($request, $context);
        $data = $report->build($from, $to, $accountId, $contactId);
        $showBalance = $contactId === null;

        $audit->log('report.exported', after: [
            'report' => 'general_ledger',
            'from' => $data['from'],
            'to' => $data['to'],
            'account_id' => $data['account_id'],
        ]);

        return response()->streamDownload(function () use ($data, $showBalance) {
            $handle = fopen('php://output', 'w');

            $header = ['Code', 'Name', 'Date', 'Entry', 'Source', 'Contact', 'Debit', 'Credit'];
            if ($showBalance) {
                $header[] = 'Balance';
            }
            fputcsv($handle, $header);

            foreach ($data['accounts'] as $account) {
                if ($showBalance) {
                    fputcsv($handle, [
                        $account['code'], $account['name'], '', '', 'Opening balance', '', '', '',
                        Money::minorToDecimalString($account['opening_balance']),
                    ]);
                }

                foreach ($account['lines'] as $line) {
                    $row = [
                        $account['code'],
                        $account['name'],
                        $line['entry_date'],
                        "Entry #{$line['entry_id']}",
                        $line['source_label'],
                        $line['contact_name'] ?? '',
                        Money::minorToDecimalString($line['debit']),
                        Money::minorToDecimalString($line['credit']),
                    ];
                    if ($showBalance) {
                        $row[] = Money::minorToDecimalString($line['running_balance']);
                    }
                    fputcsv($handle, $row);
                }

                if ($showBalance) {
                    fputcsv($handle, [
                        $account['code'], $account['name'], '', '', 'Closing balance', '', '', '',
                        Money::minorToDecimalString($account['closing_balance']),
                    ]);
                }
            }

            fclose($handle);
        }, "general-ledger-{$data['from']}-to-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Accepts the drill-down query params other reports link in with:
     * ?account={id}&from=&to=&contact={id}. Missing from/to fall back
     * independently (mirrors ProfitAndLossController's default), so a link
     * that only passes `to` (e.g. Balance Sheet's as-of date) still gets a
     * sensible current-fiscal-year-to-date range rather than an error.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int, 3: ?int}
     */
    private function resolveParams(Request $request, CompanyContext $context): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'account' => ['nullable', 'integer'],
            'contact' => ['nullable', 'integer'],
        ]);

        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $period->currentFiscalYearStart($today);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $today;

        return [
            $from,
            $to,
            isset($validated['account']) ? (int) $validated['account'] : null,
            isset($validated['contact']) ? (int) $validated['contact'] : null,
        ];
    }
}
