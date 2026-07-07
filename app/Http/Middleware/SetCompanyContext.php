<?php // app/Http/Middleware/SetCompanyContext.php
namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;

class SetCompanyContext
{
    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            $company = $user->currentCompany;
            if (! $company || ! $user->belongsToCompany($company)) {
                $company = $user->companies()->first();
                $user->forceFill(['current_company_id' => $company?->id])->save();
            }
            if ($company) {
                $this->context->set($company);
            }
        }
        return $next($request);
    }
}
