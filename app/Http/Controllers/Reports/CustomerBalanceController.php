<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Reports\ContactLedgerReport;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer Balance Summary + Detail (Phase 7 Task 6): every customer's net
 * AR balance as-of a date, tied to the 1100 control account
 * (ContactLedgerReport — see its class docblock for the full FX/opening/void
 * reasoning this mirrors from AgingReport). Detail is the per-customer
 * ledger card (every AR-affecting transaction + running balance), reached
 * via a customer picker exactly like AccountQuickReportController's account
 * picker (`report` is null until a `contact_id` is chosen).
 */
class CustomerBalanceController extends Controller
{
    public function summary(Request $request, ContactLedgerReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/CustomerBalanceSummary', [
            'report' => $report->customerBalanceSummary($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Customer, Balance — money rendered as exact decimal
     * strings via Money::minorToDecimalString, mirroring every other
     * Reports/*Controller::exportCsv. A final Totals row carries the grand
     * total for an at-a-glance tie-out check outside the app.
     */
    public function summaryExportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->customerBalanceSummary($asOf);

        $audit->log('report.exported', after: ['report' => 'customer_balance_summary', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Customer', 'Balance']);

            foreach ($data['contacts'] as $row) {
                fputcsv($handle, [$row['name'], Money::minorToDecimalString($row['balance'])]);
            }

            fputcsv($handle, ['Total', Money::minorToDecimalString($data['grand_total'])]);

            fclose($handle);
        }, "customer-balance-summary-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Accepts ?contact_id=&as_of= — contact_id is optional (the picker
     * screen is a valid state with nothing selected yet, same convention as
     * AccountQuickReportController::show).
     */
    public function detail(Request $request, ContactLedgerReport $report): Response
    {
        [$asOf, $contactId] = $this->resolveDetailParams($request);

        return Inertia::render('Reports/CustomerBalanceDetail', [
            'report' => $contactId !== null ? $report->customerBalanceDetail($asOf, $contactId) : null,
            'contacts' => $this->customerOptions(),
            'asOf' => $asOf->toDateString(),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /** Streamed CSV: Date, Type, Reference, Amount, Running balance. `contact_id` is required — there's nothing sensible to export without one. */
    public function detailExportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : CarbonImmutable::today();
        $data = $report->customerBalanceDetail($asOf, (int) $validated['contact_id']);

        $audit->log('report.exported', after: [
            'report' => 'customer_balance_detail',
            'contact_id' => $validated['contact_id'],
            'as_of' => $data['as_of'],
        ]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Date', 'Type', 'Reference', 'Amount', 'Running balance']);

            foreach ($data['transactions'] as $row) {
                fputcsv($handle, [
                    $row['date'],
                    ucfirst($row['type']),
                    $row['reference'],
                    Money::minorToDecimalString($row['amount']),
                    Money::minorToDecimalString($row['running_balance']),
                ]);
            }

            fclose($handle);
        }, "customer-balance-detail-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return list<array{id:int, name:string}> */
    private function customerOptions(): array
    {
        return Contact::query()
            ->whereIn('type', [ContactType::Customer->value, ContactType::Both->value])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Contact $contact) => ['id' => $contact->id, 'name' => $contact->name])
            ->values()
            ->all();
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }

    /** @return array{0: CarbonImmutable, 1: ?int} */
    private function resolveDetailParams(Request $request): array
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'contact_id' => ['nullable', 'integer'],
        ]);

        $asOf = isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : CarbonImmutable::today();

        return [$asOf, isset($validated['contact_id']) ? (int) $validated['contact_id'] : null];
    }
}
