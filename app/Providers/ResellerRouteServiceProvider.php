<?php

namespace App\Providers;

use App\Models\Reseller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ResellerRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Only register custom domain reseller routes at request time
        $this->app->booted(function () {
            $host       = str_replace(':8000', '', request()->getHost());
            $baseDomain = config('app.base_domain', 'virextra.com');

            $isMainDomain = $host === $baseDomain || $host === 'localhost' || $host === '127.0.0.1';
            $isSubdomain  = str_ends_with($host, '.' . $baseDomain);

            // Only load reseller routes for actual custom domains
            if (!$isMainDomain && !$isSubdomain) {
                Route::middleware('web')
                    ->group(base_path('routes/reseller.php'));
            }
        });
    }
}