<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireCompany
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->companies()->exists()) {
            return redirect()->route('onboarding.company');
        }
        return $next($request);
    }
}
