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

        if (!$reseller) {
            abort(404);
        }

        return $next($request);
    }
}