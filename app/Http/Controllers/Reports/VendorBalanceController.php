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
 * Vendor Balance Summary + Detail — the AP-side mirror of
 * CustomerBalanceController, tied to the 2000 control account.
 */
class VendorBalanceController extends Controller
{
    public function summary(Request $request, ContactLedgerReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/VendorBalanceSummary', [
            'report' => $report->vendorBalanceSummary($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    public function summaryExportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->vendorBalanceSummary($asOf);

        $audit->log('report.exported', after: ['report' => 'vendor_balance_summary', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Vendor', 'Balance']);

            foreach ($data['contacts'] as $row) {
                fputcsv($handle, [$row['name'], Money::minorToDecimalString($row['balance'])]);
            }

            fputcsv($handle, ['Total', Money::minorToDecimalString($data['grand_total'])]);

            fclose($handle);
        }, "vendor-balance-summary-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function detail(Request $request, ContactLedgerReport $report): Response
    {
        [$asOf, $contactId] = $this->resolveDetailParams($request);

        return Inertia::render('Reports/VendorBalanceDetail', [
            'report' => $contactId !== null ? $report->vendorBalanceDetail($asOf, $contactId) : null,
            'contacts' => $this->vendorOptions(),
            'asOf' => $asOf->toDateString(),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    public function detailExportCsv(Request $request, ContactLedgerReport $report, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = isset($validated['as_of']) ? CarbonImmutable::parse($validated['as_of']) : CarbonImmutable::today();
        $data = $report->vendorBalanceDetail($asOf, (int) $validated['contact_id']);

        $audit->log('report.exported', after: [
            'report' => 'vendor_balance_detail',
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
        }, "vendor-balance-detail-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return list<array{id:int, name:string}> */
    private function vendorOptions(): array
    {
        return Contact::query()
            ->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value])
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
