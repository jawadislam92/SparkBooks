<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IntegrityRun;
use App\Models\MonthEndClose;
use App\Services\IntegrityChecker;
use App\Services\MonthEndCloseService;
use App\Services\Reports\ReportPeriod;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The month-end close checklist (spec §17.5, Phase 6 Task 5) — a guided
 * SCREEN over existing machinery, not a new accounting concept. Every
 * mutating action here either mirrors an existing controller's own logic
 * (PeriodLockController::update for the lock step, HealthController::run for
 * the integrity-check step) or writes to the lightweight, non-ledger
 * MonthEndClose progress record (the two manual-acknowledge steps). No route
 * here ever touches journal_lines/journal_entries directly.
 *
 * Gated entirely by `periods.lock` (route middleware) — the same
 * Owner/Accountant-only ability that gates the period lock itself
 * (config/permissions.php), since "who may close the books" is exactly "who
 * may move the lock". Unlike PeriodLockController::edit's broader view/manage
 * split, there is no read-only viewer role for this screen.
 */
class MonthEndCloseController extends Controller
{
    public function show(Request $request, CompanyContext $context, MonthEndCloseService $service): Response
    {
        $company = $context->get();
        $monthEnd = $this->resolveMonthEnd($request, $company);

        $progress = $this->findProgress($company, $monthEnd);

        return Inertia::render('Close/Index', [
            'monthEnd' => $monthEnd->toDateString(),
            'steps' => $service->steps($company, $monthEnd, $progress),
            'completedAt' => $progress?->completed_at?->toIso8601String(),
            'exportRange' => $this->exportRange($company, $monthEnd),
        ]);
    }

    /**
     * Steps 2/3 (spec §17.5): toggles the manual-acknowledge state for
     * whichever report the user says they reviewed. Revisitable both ways —
     * ticking again clears it back to null, exactly like unchecking a
     * checkbox — since neither review is the irreversible-ish act (that's
     * the lock, below).
     */
    public function acknowledge(Request $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'month_end' => ['required', 'date'],
            'step' => ['required', 'string', Rule::in(['ar_ap_aging', 'trial_balance'])],
            'acknowledged' => ['required', 'boolean'],
        ]);

        $monthEnd = CarbonImmutable::parse($validated['month_end'])->toDateString();
        $column = $validated['step'] === 'ar_ap_aging' ? 'ar_ap_reviewed_at' : 'trial_balance_reviewed_at';

        $progress = MonthEndClose::query()->firstOrCreate(['month_end' => $monthEnd]);
        $before = $progress->{$column}?->toIso8601String();
        $progress->update([$column => $validated['acknowledged'] ? now() : null]);

        $audit->log(
            'month_end_close.step_acknowledged',
            $progress,
            before: ['step' => $validated['step'], $column => $before],
            after: ['step' => $validated['step'], $column => $progress->{$column}?->toIso8601String()],
        );

        return redirect()->route('close.show', ['month_end' => $monthEnd]);
    }

    /**
     * Step 5 (spec §17.5): runs IntegrityChecker for the company and persists
     * the result to `integrity_runs` — the EXACT SAME persistence shape as
     * HealthController::run (same model, same `integrity.checked` audit
     * action), so a run triggered from either screen updates the single
     * source of truth both screens read. Deliberately not extracted into a
     * shared service: the brief calls this one out by name as a screen to
     * mirror, and the whole action is five lines.
     */
    public function runIntegrityCheck(Request $request, IntegrityChecker $checker, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['month_end' => ['required', 'date']]);

        $company = $context->get();
        $findings = $checker->checkCompany($company);
        $clean = $findings === [];

        $run = IntegrityRun::query()->create([
            'ran_at' => now(),
            'findings' => $clean ? null : $findings,
            'clean' => $clean,
        ]);

        $audit->log('integrity.checked', $run, after: ['clean' => $clean, 'findings_count' => count($findings)]);

        return redirect()->route('close.show', ['month_end' => $validated['month_end']]);
    }

    /**
     * Step 6 (spec §17.5) — the one irreversible-ish act: invokes the SAME
     * period-lock mechanism PeriodLockController::update uses (sets
     * `companies.books_locked_through`, same `periods.lock_moved` audit
     * action, same "can't lock a future date" guard), just pinned to this
     * checklist's own month-end date instead of an arbitrary user-typed one.
     * Still movable afterward via Settings > Books or by running this same
     * action for a later month end — locking is not itself a one-way door.
     */
    public function lock(Request $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['month_end' => ['required', 'date']]);
        $monthEnd = CarbonImmutable::parse($validated['month_end']);

        if ($monthEnd->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'month_end' => "You can't lock a period that hasn't ended yet.",
            ]);
        }

        $company = $context->get();
        $before = $company->books_locked_through?->toDateString();
        $monthEndDate = $monthEnd->toDateString();

        $company->update(['books_locked_through' => $monthEndDate]);

        $audit->log(
            'periods.lock_moved',
            $company,
            before: ['books_locked_through' => $before],
            after: ['books_locked_through' => $monthEndDate],
        );

        return redirect()->route('close.show', ['month_end' => $monthEndDate]);
    }

    /**
     * Marks the close itself complete (spec §17.5: "completing the close is
     * audit-logged") — a distinct action from any single step above. Requires
     * the period to actually be locked through the month end first: a "close"
     * that isn't backed by a real lock would just be a label, and the lock is
     * the one piece of real enforcement (LedgerService) this whole checklist
     * exists to lead up to. Revisitable like every other step here — nothing
     * stops calling this again (e.g. after re-running a step), it simply
     * bumps completed_at/completed_by.
     */
    public function complete(Request $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['month_end' => ['required', 'date']]);
        $monthEnd = CarbonImmutable::parse($validated['month_end']);
        $company = $context->get();

        $lockedThrough = $company->books_locked_through;
        if ($lockedThrough === null || $lockedThrough->lt($monthEnd)) {
            throw ValidationException::withMessages([
                'month_end' => "Lock the period through {$monthEnd->toDateString()} before completing the close.",
            ]);
        }

        $progress = MonthEndClose::query()->firstOrCreate(['month_end' => $monthEnd->toDateString()]);
        $progress->update(['completed_at' => now(), 'completed_by' => auth()->id()]);

        $audit->log('period.closed', $company, after: ['month_end' => $monthEnd->toDateString()]);

        return redirect()->route('close.show', ['month_end' => $monthEnd->toDateString()]);
    }

    /**
     * Resolves the month-end date this checklist is for: an explicit
     * `month_end` query param, or (default) the month-end right after the
     * company's current lock — the natural "next period to close" — falling
     * back to last calendar month if nothing is locked yet.
     */
    private function resolveMonthEnd(Request $request, Company $company): CarbonImmutable
    {
        $validated = $request->validate(['month_end' => ['nullable', 'date']]);

        if (isset($validated['month_end'])) {
            return CarbonImmutable::parse($validated['month_end'])->startOfDay();
        }

        if ($company->books_locked_through !== null) {
            return CarbonImmutable::parse($company->books_locked_through)
                ->addMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        return CarbonImmutable::today()->subMonthNoOverflow()->endOfMonth()->startOfDay();
    }

    private function findProgress(Company $company, CarbonImmutable $monthEnd): ?MonthEndClose
    {
        return MonthEndClose::query()->where('month_end', $monthEnd->toDateString())->first();
    }

    /**
     * Step 7's link target (spec §17.5 point 7): the accountant package for
     * "the now-frozen period" — this fiscal year to date, anchored at the
     * month end being closed (the same fiscal-year math
     * AccountantPackageController::resolveRange already uses, just anchored
     * here instead of at "today").
     *
     * @return array{from: string, to: string}
     */
    private function exportRange(Company $company, CarbonImmutable $monthEnd): array
    {
        $period = new ReportPeriod($company->fiscal_year_start_month);

        return [
            'from' => $period->currentFiscalYearStart($monthEnd)->toDateString(),
            'to' => $monthEnd->toDateString(),
        ];
    }
}
