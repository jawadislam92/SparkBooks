<?php

namespace App\Http\Controllers;

use App\Services\AccountantPackageService;
use App\Services\FullDataExportService;
use App\Services\Reports\ReportPeriod;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Backup & Export screen (spec §11, Phase 7 Task 7) — Settings-area
 * process-UI over EXISTING Phase-6 machinery, plus a new full-data export
 * escape hatch. NO new accounting logic: this controller only orchestrates
 * two already-built services (AccountantPackageService, unchanged; the new
 * FullDataExportService, which itself performs no accounting math either —
 * see its own docblock) and reads the filesystem for backup status.
 *
 * The whole group (show + both downloads) is gated `exports.run` in
 * routes/web.php — unlike the Accountant Package screen (which splits
 * `reports.view` for viewing from `exports.run` for downloading), this
 * screen's entire purpose IS running exports, so there is no broader
 * "may view" audience to carve out; Staff (who holds neither ability) gets
 * a 403 on every route here, including the bare show page.
 */
class BackupExportController extends Controller
{
    public function show(Request $request, CompanyContext $context): Response
    {
        [$from, $to] = $this->resolveRange($request, $context);

        return Inertia::render('Settings/BackupExport', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'backupStatus' => $this->backupStatus(),
        ]);
    }

    /**
     * Reuses AccountantPackageService verbatim (the Phase 6 Task 4 "one-click
     * ... escape hatch" package) — this route is just a second front door to
     * the same download, audit-logged with the SAME `accountant_package.
     * exported` action as its original route
     * (Reports\AccountantPackageController::download): it is genuinely the
     * same export, just reachable from the Backup & Export screen too.
     */
    public function downloadAccountantPackage(
        Request $request,
        AccountantPackageService $service,
        CompanyContext $context,
        AuditLogger $audit,
    ): BinaryFileResponse {
        [$from, $to] = $this->resolveRange($request, $context);

        $zipPath = $service->build($from, $to);

        // Same pre-stream failure-path resource hygiene as
        // Reports\AccountantPackageController::download: deleteFileAfterSend
        // only covers the success path, so a failure between building the
        // zip and starting the stream (e.g. the audit-log write itself)
        // unlinks the temp file before rethrowing.
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
     * The new full-data export escape hatch (App\Services\
     * FullDataExportService — accounts/contacts/items/journal entries+lines/
     * invoices+payments/bills+payments as one zip of CSVs, scoped to the
     * current company). `date` is read from the request purely to make the
     * downloaded filename deterministic in tests (never from now()/today()
     * directly) — the export itself is a full point-in-time snapshot, not
     * time-windowed, so `date` doesn't filter anything.
     */
    public function downloadFullExport(
        Request $request,
        FullDataExportService $service,
        CompanyContext $context,
        AuditLogger $audit,
    ): BinaryFileResponse {
        $validated = $request->validate(['date' => ['nullable', 'date']]);
        $date = isset($validated['date']) ? CarbonImmutable::parse($validated['date']) : CarbonImmutable::today();

        $zipPath = $service->build();

        // Same pre-stream failure-path resource hygiene as
        // downloadAccountantPackage/AccountantPackageController above.
        try {
            $audit->log('full_data_export.exported', after: [
                'tables' => FullDataExportService::TABLES,
            ]);

            $companySlug = Str::slug($context->get()->name) ?: 'company';
            $filename = "full-export-{$companySlug}-{$date->toDateString()}.zip";

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
     * Reports\AccountantPackageController::resolveRange (same ReportPeriod
     * helper, same default).
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

    /**
     * Reads the backup status directly off the filesystem BackupDatabase
     * (Phase 6 Task 6, app/Console/Commands/BackupDatabase.php) already
     * writes to — there is no dedicated backup-log DB table/model in this
     * app, only the timestamped `sparkbooks-backup-<ts>.zip` artifacts
     * themselves under config('backup.local_path'). Their filenames are
     * lexically sortable by timestamp (Y-m-d_His), so the last one
     * alphabetically is also the most recent — no need to stat() every file
     * just to compare mtimes. Gracefully reports "unavailable" (never
     * throws) when the directory doesn't exist yet or holds no matching
     * artifact, e.g. a fresh install or a company whose backups run
     * entirely off-host — the UI shows a calm "backups run nightly on the
     * server" note in that case rather than an error.
     *
     * @return array{available: bool, filename?: string, last_backup_at?: string}
     */
    private function backupStatus(): array
    {
        $localPath = rtrim((string) config('backup.local_path'), '/\\');

        if (! is_dir($localPath)) {
            return ['available' => false];
        }

        $files = collect(scandir($localPath) ?: [])
            ->filter(fn (string $f) => str_starts_with($f, 'sparkbooks-backup-') && str_ends_with($f, '.zip'))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            return ['available' => false];
        }

        $latest = $files->last();
        $mtime = @filemtime("{$localPath}/{$latest}");

        return [
            'available' => true,
            'filename' => $latest,
            'last_backup_at' => $mtime !== false
                ? CarbonImmutable::createFromTimestamp($mtime)->toIso8601String()
                : null,
        ];
    }
}
