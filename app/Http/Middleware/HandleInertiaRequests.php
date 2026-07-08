<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Illuminate\Http\Request;
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
        ];
    }
}
