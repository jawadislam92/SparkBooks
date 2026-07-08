<?php

namespace App\Providers;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        foreach (config('permissions.catalog') as $permission) {
            Gate::define($permission, function ($user) use ($permission) {
                $context = app(CompanyContext::class);
                if (! $context->has()) {
                    return false;
                }
                return $user->roleIn($context->get())?->allows($permission) ?? false;
            });
        }
    }
}
