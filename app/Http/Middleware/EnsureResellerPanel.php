<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureResellerPanel
{
    public function handle(Request $request, Closure $next)
    {
        $reseller = app()->bound('current_reseller')
            ? app('current_reseller')
            : null;

        // No reseller resolved = this is the main domain, not a panel request.
        // Let the router decide — it simply won't match reseller-prefixed routes.
        if (!$reseller) {
            abort(404, 'Panel not found.');
        }

        return $next($request);
    }
}