<?php

namespace App\Http\Controllers;

use App\Models\IntegrityRun;
use App\Services\IntegrityChecker;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Health — the on-demand counterpart to the scheduled
 * `ledger:verify` command (App\Console\Commands\VerifyLedgerIntegrity).
 * Both routes are gated by `can:audit.view` route middleware (Owner +
 * Accountant only; see config/permissions.php).
 */
class HealthController extends Controller
{
    public function show(CompanyContext $context): Response
    {
        $latest = IntegrityRun::query()->orderByDesc('ran_at')->first();

        return Inertia::render('Settings/Health', [
            'latestRun' => $latest === null ? null : [
                'ranAt' => $latest->ran_at->toIso8601String(),
                'clean' => $latest->clean,
                'findings' => $latest->findings ?? [],
            ],
        ]);
    }

    public function run(IntegrityChecker $checker, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $company = $context->get();
        $findings = $checker->checkCompany($company);
        $clean = $findings === [];

        $run = IntegrityRun::query()->create([
            'ran_at' => now(),
            'findings' => $clean ? null : $findings,
            'clean' => $clean,
        ]);

        $audit->log('integrity.checked', $run, after: ['clean' => $clean, 'findings_count' => count($findings)]);

        return redirect()->route('settings.health');
    }
}
