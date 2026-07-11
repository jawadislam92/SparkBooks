<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\AgingReport;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApAgingController extends Controller
{
    public function show(Request $request, AgingReport $report): Response
    {
        $asOf = $this->resolveAsOf($request);

        return Inertia::render('Reports/ApAging', [
            'report' => $report->buildPayables($asOf),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Streamed CSV: Contact, one column per bucket, Total — exact AP mirror
     * of ArAgingController::exportCsv(). See that method's docblock.
     */
    public function exportCsv(Request $request, AgingReport $report, AuditLogger $audit): StreamedResponse
    {
        $asOf = $this->resolveAsOf($request);
        $data = $report->buildPayables($asOf);

        $audit->log('report.exported', after: ['report' => 'ap_aging', 'as_of' => $data['as_of']]);

        return response()->streamDownload(function () use ($data) {
            $this->writeCsv($data);
        }, "ap-aging-{$data['as_of']}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @param array{buckets: list<string>, bucket_labels: array<string,string>, contacts: list<array{contact_id:int, name:string, buckets: array<string,int>, total:int}>, totals: array<string,int>, grand_total:int, control_balance:int} $data */
    private function writeCsv(array $data): void
    {
        $handle = fopen('php://output', 'w');

        $bucketLabels = array_map(fn (string $key): string => $data['bucket_labels'][$key], $data['buckets']);
        fputcsv($handle, ['Contact', ...$bucketLabels, 'Total']);

        foreach ($data['contacts'] as $row) {
            $amounts = array_map(fn (string $key): string => Money::minorToDecimalString($row['buckets'][$key]), $data['buckets']);
            fputcsv($handle, [$row['name'], ...$amounts, Money::minorToDecimalString($row['total'])]);
        }

        $totalAmounts = array_map(fn (string $key): string => Money::minorToDecimalString($data['totals'][$key]), $data['buckets']);
        fputcsv($handle, ['Totals', ...$totalAmounts, Money::minorToDecimalString($data['grand_total'])]);

        fclose($handle);
    }

    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return isset($validated['as_of'])
            ? CarbonImmutable::parse($validated['as_of'])
            : CarbonImmutable::today();
    }
}
