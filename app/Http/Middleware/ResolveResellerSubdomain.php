<?php

namespace App\Http\Middleware;

use App\Models\Reseller;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ResolveResellerSubdomain
{
    public function handle(Request $request, Closure $next)
    {
        $host = str_replace(':8000', '', $request->getHost());
        $baseDomain = config('app.base_domain', 'lvh.me');
        
        // Debug - remove after testing
        \Log::info('Reseller middleware', [
            'host' => $host,
            'baseDomain' => $baseDomain,
            'ends_with' => str_ends_with($host, '.' . $baseDomain),
        ]);
        $reseller = null;

        // Check for subdomain: e.g. acme.boosterr.xyz → subdomain = "acme"
        if (str_ends_with($host, '.' . $baseDomain)) {
            $subdomain = str_replace('.' . $baseDomain, '', $host);
            $reseller  = Reseller::where('subdomain', $subdomain)
                                 ->where('status', 'active')
                                 ->first();
        }

        // Also support fully custom domains (e.g. panel.acme.ng)
        if (!$reseller && $host !== $baseDomain && !str_ends_with($host, '.' . $baseDomain)) {
            $reseller = Reseller::where('custom_domain', $host)
                                ->where('status', 'active')
                                ->first();
        }

        if ($reseller) {
            // Make the reseller available everywhere
            app()->instance('current_reseller', $reseller);
            View::share('currentReseller', $reseller);
            $request->attributes->set('reseller', $reseller);
        }

        return $next($request);
    }
}