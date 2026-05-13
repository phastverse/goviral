<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Provider;
use App\Services\CurrencyService;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    /**
     * Show all cached rates + provider currency summary.
     */
    public function index()
    {
        $rates     = CurrencyService::getCachedRates();
        $providers = Provider::orderBy('priority')->get(['id', 'name', 'currency', 'is_active']);

        return view('admin.exchange-rates.index', compact('rates', 'providers'));
    }

    /**
     * Force-refresh a single rate pair.
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'from' => 'required|alpha|size:3',
            'to'   => 'nullable|alpha|size:3',
        ]);

        $from = strtoupper($request->from);
        $to   = strtoupper($request->to ?? CurrencyService::PLATFORM_CURRENCY);

        try {
            $rate = CurrencyService::forceRefresh($from, $to);

            if ($rate !== null) {
                return back()->with('success', "Rate refreshed: 1 {$from} = ₦" . number_format($rate, 2) . " NGN");
            }

            return back()->with('error', "Both APIs failed for {$from} → {$to}. Check your internet connection or try again.");

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Refresh all rates for currently configured providers.
     */
    public function refreshAll()
    {
        $currencies = Provider::active()
            ->pluck('currency')
            ->map(fn($c) => strtoupper(trim($c)))
            ->filter(fn($c) => $c && $c !== CurrencyService::PLATFORM_CURRENCY)
            ->unique()
            ->values()
            ->all();

        $results  = [];
        $failures = 0;

        foreach ($currencies as $currency) {
            $rate = CurrencyService::forceRefresh($currency, CurrencyService::PLATFORM_CURRENCY);
            if ($rate !== null) {
                $results[] = "1 {$currency} = ₦" . number_format($rate, 2);
            } else {
                $results[] = "{$currency}: Failed";
                $failures++;
            }
        }

        if (empty($currencies)) {
            return back()->with('info', 'No non-NGN providers found. Nothing to refresh.');
        }

        $message = implode(' | ', $results);

        return $failures === 0
            ? back()->with('success', "All rates refreshed: {$message}")
            : back()->with('error', "Some rates failed: {$message}");
    }
}