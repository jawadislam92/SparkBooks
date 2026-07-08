<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PeriodLockController extends Controller
{
    /**
     * Viewing the lock is broader than changing it: reports.view holders
     * (e.g. Admin) may see the current lock date read-only, while only
     * periods.lock holders (Owner/Accountant) may change it — the POST
     * action enforces that narrower gate via `can:periods.lock` route
     * middleware.
     */
    public function edit(CompanyContext $context): Response
    {
        abort_unless(Gate::any(['periods.lock', 'reports.view']), 403);

        $company = $context->get();

        return Inertia::render('Settings/Books', [
            'lockedThrough' => $company->books_locked_through?->toDateString(),
            'canManage' => Gate::allows('periods.lock'),
        ]);
    }

    /**
     * Sets, moves, or clears the books' lock date. A null/empty value
     * unlocks. Moving the lock backward (an earlier date, or clearing it
     * entirely) is deliberately allowed — that's an Owner/Accountant
     * judgement call, not something this endpoint should second-guess.
     * Authorization is enforced by the `can:periods.lock` route middleware.
     */
    public function update(Request $request, CompanyContext $context, AuditLogger $audit): RedirectResponse
    {
        // Normalize an empty-string date (the UI's "unlock" affordance clears
        // the field) to null before validation, so the `date` rule doesn't
        // choke on ''.
        $request->merge([
            'locked_through' => $request->filled('locked_through') ? $request->input('locked_through') : null,
        ]);

        $validated = $request->validate([
            'locked_through' => ['nullable', 'date'],
        ]);

        $lockedThrough = $validated['locked_through'] === null
            ? null
            : Carbon::parse($validated['locked_through'])->toDateString();

        if ($lockedThrough !== null && Carbon::parse($lockedThrough)->gt(Carbon::today())) {
            throw ValidationException::withMessages([
                'locked_through' => "You can't lock a period that hasn't ended yet.",
            ]);
        }

        $company = $context->get();
        $before = $company->books_locked_through?->toDateString();

        $company->update(['books_locked_through' => $lockedThrough]);

        $audit->log(
            'periods.lock_moved',
            $company,
            before: ['books_locked_through' => $before],
            after: ['books_locked_through' => $lockedThrough],
        );

        return redirect()->route('settings.books');
    }
}
