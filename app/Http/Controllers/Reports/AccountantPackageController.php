<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\AccountantPackageService;
use App\Services\Reports\ReportPeriod;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Accountant Package screen (spec §11, Phase 6 Task 4) — "one-click ...
 * This is also the escape hatch". Sits in the same reports group as every
 * other Phase 5 report (gated `reports.view`); the actual download is
 * additionally gated `exports.run`, the same double-gate shape every other
 * report's own CSV export route already uses (routes/web.php).
 */
class AccountantPackageController extends Controller
{
    public function show(Request $request, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Reports/AccountantPackage', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'canExport' => Gate::allows('exports.run'),
        ]);
    }

    /**
     * Builds the zip via AccountantPackageService, audit-logs the export
     * (§17.8 — `accountant_package.exported` with the period, same
     * completeness requirement as every other export endpoint), then streams
     * it via a plain file download and deletes the temp file once sent.
     */
    public function download(
        Request $request,
        AccountantPackageService $service,
        CompanyContext $context,
        AuditLogger $audit,
    ): BinaryFileResponse {
        [$from, $to] = $this->resolveRange($request, $context);

        $zipPath = $service->build($from, $to);

        // The zip exists on disk from here on. deleteFileAfterSend(true) cleans
        // it up on the SUCCESS path (once the response finishes streaming); this
        // try/catch covers the PRE-stream failure path — e.g. if $audit->log()
        // hits a DB hiccup, we unlink the temp file before rethrowing so a
        // failed export never leaks it (the brief's "no temp-file leak on
        // success OR exception").
        try {
            $audit->log('accountant_package.exported', after: [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

            $companySlug = Str::slug($context->get()->name) ?: 'company';
            $filename = "accountant-package-{$companySlug}-{$from->toDateString()}-{$to->toDateString()}.zip";

            return response()
                ->download($zipPath, $filename, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw $e;
        }
    }

    /**
     * Default: current fiscal year to date — identical convention to
     * ProfitAndLossController::resolveRange (T1's ReportPeriod, using the
     * company's fiscal_year_start_month).
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
