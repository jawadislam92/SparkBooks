<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The subset of config('permissions.catalog') the sidebar nav (see
     * resources/js/Layouts/AppShell.tsx) — and, as of Phase 3 Task 11,
     * Invoices/Show's action bar — needs to decide what to render for the
     * current role. This is NOT a general-purpose authorization export —
     * every route those links point at is still independently gated by its
     * own `can:` middleware/Gate::allows check; this list only has to stay in
     * sync with whatever abilities the nav (or a page's own action gating)
     * conditionally renders on.
     *
     * @var list<string>
     */
    private const NAV_ABILITIES = [
        'invoices.draft',
        // Task 11: Invoices/Show gates its Send/Record payment/Void buttons
        // on these three — narrower than invoices.draft (see routes/web.php's
        // own comments: invoices.send/payments.record/invoices.void are each
        // their own gate, Staff holds none of them).
        'invoices.send',
        'payments.record',
        'invoices.void',
        // Phase 4 Task 2: draft-only expense/bill CRUD nav links.
        'expenses.draft',
        'bills.draft',
        'contacts.manage',
        'items.manage',
        'accounts.manage',
        'ledger.post_manual',
        'bank_accounts.manage',
        'transfers.create',
        'other_receipts.create',
        'reports.view',
        'tax_rates.manage',
        'periods.lock',
        'settings.manage',
        'audit.view',
    ];

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // These are closures on purpose: SetCompanyContext runs later in the
            // `web` middleware group than this middleware does, and Inertia doesn't
            // invoke shared-prop closures until it serializes the response — by
            // which point SetCompanyContext (and any current-company repair it does)
            // has already run. Eagerly reading $request->user()->currentCompany here
            // would race that repair and could share a stale/wrong company.
            'auth' => [
                'user' => fn () => $request->user(),
            ],
            'companies' => fn () => $request->user()
                ? $request->user()->companies()->get()
                    ->map(fn ($company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'role' => $company->pivot->role,
                    ])
                    ->values()
                    ->all()
                : [],
            'currentCompany' => function () {
                $context = app(CompanyContext::class);

                if (! $context->has()) {
                    return null;
                }

                $company = $context->get();

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'base_currency' => $company->base_currency,
                ];
            },
            // Same closure-timing reasoning as currentCompany above: Gate
            // checks depend on CompanyContext, which isn't set yet at the
            // point `share()` runs.
            'can' => fn () => $request->user()
                ? collect(self::NAV_ABILITIES)
                    ->mapWithKeys(fn (string $ability) => [$ability => Gate::allows($ability)])
                    ->all()
                : [],
        ];
    }
}
