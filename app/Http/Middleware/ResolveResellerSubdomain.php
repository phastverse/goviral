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
        $host       = str_replace(':8000', '', $request->getHost());
        $baseDomain = config('app.base_dorimartmain', 'virextra.com');

        // On localhost or main domain, skip reseller resolution entirely
        if ($host === 'localhost' || $host === $baseDomain) {
            return $next($request);
        }

        $reseller = null;

        // Subdomain: acme.virextra.com
        if (str_ends_with($host, '.' . $baseDomain)) {
            $subdomain = str_replace('.' . $baseDomain, '', $host);
            $reseller  = \App\Models\Reseller::where('subdomain', $subdomain)
                             ->where('status', 'active')
                             ->first();
        } else {
            // Fully custom domain: panel.acme.ng
            $reseller = \App\Models\Reseller::where('custom_domain', $host)
                             ->where('status', 'active')
                             ->first();
        }

        if ($reseller) {
            app()->instance('current_reseller', $reseller);
            \Illuminate\Support\Facades\View::share('currentReseller', $reseller);
            $request->attributes->set('reseller', $reseller);
        }

        return $next($request);
    }
}