<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * CurrencyService
 *
 * Converts a price in any currency → NGN (or any target).
 *
 * Strategy:
 *  1. Check DB cache (ExchangeRate table). If fresh (< 30 min), use it.
 *  2. Try primary API: ExchangeRate-API (https://open.er-api.com) — no key needed.
 *  3. Fallback API: Floatrates (https://www.floatrates.com/daily/{from}.json) — no key needed.
 *  4. If both fail, try Laravel cache (last known good rate for this session).
 *  5. If everything fails, throw an exception so the order fails safely.
 *
 * Usage:
 *   $ngnPrice = CurrencyService::convert(1.50, 'USD', 'NGN');
 *   $rate     = CurrencyService::getRate('USD', 'NGN');
 */
class CurrencyService
{
    // Our platform always shows prices in NGN
    public const PLATFORM_CURRENCY = 'NGN';

    // DB cache TTL in minutes
    private const CACHE_TTL = 30;

    // HTTP timeout in seconds
    private const TIMEOUT = 10;

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Convert an amount from one currency to another.
     *
     * @param  float  $amount      The amount in the source currency
     * @param  string $from        Source currency code (e.g. 'USD')
     * @param  string $to          Target currency code (default: 'NGN')
     * @return float               Converted amount, rounded to 2 dp
     * @throws \RuntimeException   If no rate can be obtained
     */
    public static function convert(float $amount, string $from, string $to = self::PLATFORM_CURRENCY): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($from === $to) return round($amount, 2);

        $rate = self::getRate($from, $to);
        return round($amount * $rate, 2);
    }

    /**
     * Convert a provider rate (per 1000 units) to NGN if needed.
     * Providers store rates as "per 1000 units in their currency".
     */
    public static function convertProviderRate(float $rate, string $providerCurrency): float
    {
        $currency = strtoupper(trim($providerCurrency));
        if ($currency === self::PLATFORM_CURRENCY) return $rate;
        return self::convert($rate, $currency, self::PLATFORM_CURRENCY);
    }

    /**
     * Get the exchange rate between two currencies.
     * Checks DB cache first, then hits live APIs.
     *
     * @throws \RuntimeException  If rate cannot be fetched
     */
    public static function getRate(string $from, string $to = self::PLATFORM_CURRENCY): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($from === $to) return 1.0;

        // 1. Check DB cache
        $cached = ExchangeRate::where('from_currency', $from)
                              ->where('to_currency', $to)
                              ->first();

        if ($cached && !$cached->isStale(self::CACHE_TTL)) {
            Log::debug("CurrencyService: Using cached rate {$from}→{$to} = {$cached->rate}");
            return $cached->rate;
        }

        // 2. Try live APIs
        $rate = self::fetchFromPrimaryApi($from, $to)
             ?? self::fetchFromFallbackApi($from, $to);

        if ($rate !== null && $rate > 0) {
            self::saveRate($from, $to, $rate, 'api');
            return $rate;
        }

        // 3. Use stale cache if available (better than failing)
        if ($cached && $cached->rate > 0) {
            Log::warning("CurrencyService: All APIs failed. Using stale rate {$from}→{$to} = {$cached->rate}");
            return $cached->rate;
        }

        // 4. Last-resort Laravel cache (in-memory fallback during same request cycle)
        $cacheKey = "exchange_rate_{$from}_{$to}";
        if (Cache::has($cacheKey)) {
            $r = Cache::get($cacheKey);
            Log::warning("CurrencyService: Using in-memory fallback rate {$from}→{$to} = {$r}");
            return $r;
        }

        throw new \RuntimeException(
            "CurrencyService: Cannot obtain exchange rate for {$from} → {$to}. Both APIs failed and no cached rate available."
        );
    }

    /**
     * Force-refresh a specific pair from the live API (admin use).
     */
    public static function forceRefresh(string $from, string $to = self::PLATFORM_CURRENCY): ?float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        $rate = self::fetchFromPrimaryApi($from, $to)
             ?? self::fetchFromFallbackApi($from, $to);

        if ($rate !== null && $rate > 0) {
            self::saveRate($from, $to, $rate, 'api');
            return $rate;
        }

        return null;
    }

    /**
     * Get all currently cached rates (for admin display).
     */
    public static function getCachedRates(): \Illuminate\Database\Eloquent\Collection
    {
        return ExchangeRate::orderBy('from_currency')->orderBy('to_currency')->get();
    }

    /**
     * Pre-warm the cache for a list of currencies → NGN.
     * Call this from a scheduled job or after saving a new provider.
     */
    public static function warmCache(array $currencies): void
    {
        foreach ($currencies as $currency) {
            $currency = strtoupper(trim($currency));
            if ($currency === self::PLATFORM_CURRENCY) continue;
            try {
                self::getRate($currency, self::PLATFORM_CURRENCY);
            } catch (\Exception $e) {
                Log::error("CurrencyService: warmCache failed for {$currency}: " . $e->getMessage());
            }
        }
    }

    // ─── Primary API: ExchangeRate-API (open endpoint, no key) ───────────────

    /**
     * https://open.er-api.com/v6/latest/{FROM}
     * Free, no key required, updates hourly.
     * Returns all pairs relative to {FROM}.
     */
    private static function fetchFromPrimaryApi(string $from, string $to): ?float
    {
        try {
            $url      = "https://open.er-api.com/v6/latest/{$from}";
            $response = Http::timeout(self::TIMEOUT)->get($url);

            if (!$response->successful()) {
                Log::warning("CurrencyService [primary]: HTTP {$response->status()} for {$from}");
                return null;
            }

            $data = $response->json();

            if (($data['result'] ?? '') !== 'success') {
                Log::warning("CurrencyService [primary]: Non-success result", $data);
                return null;
            }

            $rate = $data['rates'][$to] ?? null;

            if ($rate === null) {
                Log::warning("CurrencyService [primary]: {$to} not in response for {$from}");
                return null;
            }

            Log::info("CurrencyService [primary]: Fetched {$from}→{$to} = {$rate}");
            return (float) $rate;

        } catch (\Exception $e) {
            Log::warning("CurrencyService [primary]: Exception — " . $e->getMessage());
            return null;
        }
    }

    // ─── Fallback API: Floatrates (no key, updates every 12 hours) ───────────

    /**
     * http://www.floatrates.com/daily/{from_lowercase}.json
     * Returns an object keyed by lowercase currency code.
     * E.g. floatrates.com/daily/usd.json → { "ngn": { "rate": 1620.5, ... } }
     */
    private static function fetchFromFallbackApi(string $from, string $to): ?float
    {
        try {
            $url      = "https://www.floatrates.com/daily/" . strtolower($from) . ".json";
            $response = Http::timeout(self::TIMEOUT)->get($url);

            if (!$response->successful()) {
                Log::warning("CurrencyService [floatrates]: HTTP {$response->status()} for {$from}");
                return null;
            }

            $data = $response->json();
            $key  = strtolower($to);

            if (!isset($data[$key]['rate'])) {
                Log::warning("CurrencyService [floatrates]: {$to} not found in {$from} feed");
                return null;
            }

            $rate = (float) $data[$key]['rate'];
            Log::info("CurrencyService [floatrates]: Fetched {$from}→{$to} = {$rate}");
            return $rate;

        } catch (\Exception $e) {
            Log::warning("CurrencyService [floatrates]: Exception — " . $e->getMessage());
            return null;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function saveRate(string $from, string $to, float $rate, string $source): void
    {
        try {
            ExchangeRate::updateOrCreate(
                ['from_currency' => $from, 'to_currency' => $to],
                ['rate' => $rate, 'source' => $source, 'fetched_at' => now()]
            );

            // Also store in Laravel cache as in-memory backup
            Cache::put("exchange_rate_{$from}_{$to}", $rate, now()->addHours(2));

        } catch (\Exception $e) {
            Log::error("CurrencyService: Failed to save rate to DB — " . $e->getMessage());
        }
    }
}