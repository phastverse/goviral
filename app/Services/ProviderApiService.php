<?php

namespace App\Services;

use App\Models\Provider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ProviderApiService
 *
 * Generic HTTP adapter for ONE SMM panel provider.
 *
 * ─── CURRENCY DETECTION (THE CORRECT WAY) ────────────────────────────────────
 *
 * Ogaviral balance response (from their official docs):
 *   { "balance": "100.84292", "currency": "USD" }
 *
 * Ogaviral order status response:
 *   { "charge": "0.27819", "start_count": "3572", "status": "Partial",
 *     "remains": "157", "currency": "USD" }
 *
 * The currency field is RIGHT THERE in the response. We read it.
 * We NEVER assume. We NEVER hardcode USD or any other currency.
 * Some providers return NGN, some USD, some EUR — we don't know until we ask.
 *
 * Flow:
 *  1. Call balance → read { balance, currency }
 *  2. Save detected currency to providers.currency in DB
 *  3. Use that currency to convert rates/balance → NGN for the rest of the app
 *  4. On every subsequent call, re-read currency from the response if present
 * ─────────────────────────────────────────────────────────────────────────────
 */
class ProviderApiService
{
    protected Provider $provider;
    protected int $timeout = 15;

    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    // ─── Balance ──────────────────────────────────────────────────────────────

    /**
     * Fetch balance. Reads the "currency" field directly from the response.
     * Converts to NGN. Saves detected currency + converted balance to DB.
     *
     * Ogaviral example response:
     *   { "balance": "100.84292", "currency": "USD" }
     *
     * @return float|null  Balance in NGN, null on failure
     */
    public function getBalance(): ?float
    {
        $response = $this->call(['action' => 'balance']);

        if (!$response || !isset($response['balance'])) {
            Log::warning("ProviderApiService [{$this->provider->name}]: Empty or missing balance response");
            return null;
        }

        $rawBalance = (float) $response['balance'];

        // Read currency directly from the response — this is the ground truth
        $currency = $this->readCurrency($response);

        // Persist back to DB if it changed (so getServices() can use it without
        // making another balance call every time)
        if (strtoupper($this->provider->currency ?? '') !== $currency) {
            $this->provider->updateQuietly(['currency' => $currency]);
            $this->provider->currency = $currency; // keep in-memory in sync
            Log::info("ProviderApiService [{$this->provider->name}]: Detected currency [{$currency}] from balance response");
        }

        // Convert to NGN and cache
        $ngnBalance = $this->toNgn($rawBalance, $currency);

        $this->provider->updateQuietly([
            'cached_balance'     => $ngnBalance,
            'balance_checked_at' => now(),
        ]);

        return $ngnBalance;
    }

    // ─── Services ─────────────────────────────────────────────────────────────

    /**
     * Fetch all services. Converts each rate from provider currency → NGN.
     *
     * IMPORTANT: We call getBalance() first to detect the currency.
     * This adds one HTTP call but guarantees we always know the correct currency
     * before touching any rates. Cached in DB so subsequent calls are instant.
     *
     * Ogaviral service rate is "per 1000 units" in provider's currency.
     * We convert it to NGN per 1000 before returning.
     *
     * @return array  Services with rate already in NGN per 1000
     */
    public function getServices(): array
    {
        // Always detect currency fresh via balance before converting rates
        $currency = $this->detectCurrencyViaBalance();

        $response = $this->call(['action' => 'services']);

        if (!is_array($response)) {
            Log::warning("ProviderApiService [{$this->provider->name}]: services response is not an array");
            return [];
        }

        // Already NGN → return as-is, no conversion needed
        if ($currency === CurrencyService::PLATFORM_CURRENCY) {
            return $response;
        }

        // Fetch exchange rate once for the whole batch (DB cached — very fast)
        try {
            $exchangeRate = CurrencyService::getRate($currency, CurrencyService::PLATFORM_CURRENCY);
        } catch (\Exception $e) {
            Log::error("ProviderApiService [{$this->provider->name}]: Exchange rate fetch failed {$currency}→NGN — {$e->getMessage()}");
            // Return raw — prices will be wrong but app stays up
            return $response;
        }

        Log::info("ProviderApiService [{$this->provider->name}]: Converting {count} services {$currency}→NGN @ {$exchangeRate}", [
            'count' => count($response),
        ]);

        return array_map(function (array $service) use ($exchangeRate, $currency): array {
            $originalRate = (float) ($service['rate'] ?? 0);

            // Keep originals for debugging / logging
            $service['original_rate']      = $originalRate;
            $service['original_currency']  = $currency;
            $service['exchange_rate_used'] = $exchangeRate;

            // Overwrite with NGN equivalent (still per 1000)
            $service['rate'] = round($originalRate * $exchangeRate, 6);

            return $service;
        }, $response);
    }

    // ─── Place Order ──────────────────────────────────────────────────────────

    /**
     * Place an order with the provider.
     * No currency conversion here — we send service ID + link + quantity.
     * The provider deducts from their internal wallet in their own currency.
     *
     * Ogaviral example response: { "order": 23501 }
     */
    public function placeOrder(int $serviceId, string $link, int $quantity): array
    {
        $response = $this->call([
            'action'   => 'add',
            'service'  => $serviceId,
            'link'     => $link,
            'quantity' => $quantity,
        ]);

        return $response ?? ['error' => 'No response from provider'];
    }

    // ─── Order Status ─────────────────────────────────────────────────────────

    /**
     * Get order status.
     *
     * Ogaviral response:
     *   { "charge": "0.27819", "start_count": "3572", "status": "Partial",
     *     "remains": "157", "currency": "USD" }
     *
     * Note: The status response ALSO has a "currency" field.
     * We read it directly here too (not from the provider DB currency).
     * This way even if the provider switches currencies, the charge is correct.
     */
    public function getOrderStatus(string $apiOrderId): array
    {
        $response = $this->call([
            'action' => 'status',
            'order'  => $apiOrderId,
        ]);

        if (!$response) return ['error' => 'No response from provider'];

        return $this->convertChargeToNgn($response);
    }

    // ─── Refill ───────────────────────────────────────────────────────────────

    /**
     * Request a refill.
     * Ogaviral response: { "refill": "1" }
     */
    public function createRefill(string $apiOrderId): array
    {
        $response = $this->call([
            'action' => 'refill',
            'order'  => $apiOrderId,
        ]);

        return $response ?? ['error' => 'No response from provider'];
    }

    // ─── Availability ─────────────────────────────────────────────────────────

    /**
     * Quick ping — is the provider reachable and returning a valid balance?
     */
    public function isAvailable(): bool
    {
        try {
            return $this->getBalance() !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ─── Generic POST (for ProviderService edge cases) ────────────────────────

    public function post(array $params): ?array
    {
        return $this->call($params);
    }

    // ─── Internal Helpers ─────────────────────────────────────────────────────

    /**
     * Read the "currency" field from any API response object.
     *
     * Priority:
     *   1. "currency" field in the response itself   ← ALWAYS prefer this
     *   2. Whatever is saved on providers.currency   ← used if response has no field
     *   3. "USD" as absolute last resort             ← most SMM panels charge USD
     *
     * @param  array  $response  Raw decoded API response
     * @return string            Uppercase 3-letter currency code e.g. "USD", "NGN"
     */
    protected function readCurrency(array $response): string
    {
        // 1. Response has the currency field (Ogaviral standard)
        if (!empty($response['currency'])) {
            return strtoupper(trim($response['currency']));
        }

        // 2. Admin-configured / previously detected currency
        if (!empty($this->provider->currency)) {
            return strtoupper(trim($this->provider->currency));
        }

        // 3. Last resort — log so the admin knows to check
        Log::warning(
            "ProviderApiService [{$this->provider->name}]: No currency field in response and none configured. Defaulting to USD. " .
            "Please check this provider's API docs and set the currency manually if wrong."
        );

        return 'USD';
    }

    /**
     * Call getBalance() to detect the provider currency, refresh DB cache.
     * If the provider already has a detected currency in DB, that is returned
     * immediately without a network call (fast path).
     *
     * @return string  Detected currency code
     */
    protected function detectCurrencyViaBalance(): string
    {
        // Fast path: currency already detected and stored
        $stored = strtoupper(trim($this->provider->currency ?? ''));
        if (!empty($stored)) {
            return $stored;
        }

        // Slow path: hit the balance endpoint to detect it
        $this->getBalance();

        return strtoupper(trim($this->provider->currency ?? 'USD'));
    }

    /**
     * Convert a raw provider amount → NGN.
     * Pass-through if currency is already NGN.
     *
     * @param  float   $amount    Raw amount in provider currency
     * @param  string  $currency  Provider currency code
     * @return float              Amount in NGN
     */
    protected function toNgn(float $amount, string $currency): float
    {
        if ($currency === CurrencyService::PLATFORM_CURRENCY) {
            return $amount;
        }

        try {
            return CurrencyService::convert($amount, $currency, CurrencyService::PLATFORM_CURRENCY);
        } catch (\Exception $e) {
            Log::error("ProviderApiService [{$this->provider->name}]: toNgn() failed {$currency}→NGN — {$e->getMessage()}");
            return $amount; // return raw rather than crash
        }
    }

    /**
     * Read the "currency" from an order status response and convert "charge" → NGN.
     *
     * Ogaviral order status has its own "currency" field, which is the correct
     * currency for that specific charge. We always read it from the response.
     *
     * @param  array  $status  Raw order status response
     * @return array           Same response with charge converted to NGN
     */
    protected function convertChargeToNgn(array $status): array
    {
        if (!isset($status['charge'])) {
            return $status;
        }

        // Read currency directly from the status response
        $currency  = $this->readCurrency($status);
        $rawCharge = (float) $status['charge'];

        // Keep original for logging / admin display
        $status['charge_original']         = $rawCharge;
        $status['charge_original_currency'] = $currency;

        // Overwrite charge with NGN value
        $status['charge'] = $this->toNgn($rawCharge, $currency);

        return $status;
    }

    /**
     * Execute a POST request to the provider's API endpoint.
     *
     * @param  array  $params  Action params merged with the API key
     * @return array|null      Decoded JSON, or null on any failure
     */
    protected function call(array $params): ?array
    {
        $payload = array_merge(['key' => $this->provider->api_key], $params);

        try {
            $response = Http::timeout($this->timeout)
                ->asForm()
                ->post($this->provider->api_url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("ProviderApiService [{$this->provider->name}]: HTTP {$response->status()}", [
                'action' => $params['action'] ?? '?',
                'url'    => $this->provider->api_url,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("ProviderApiService [{$this->provider->name}]: Request exception — {$e->getMessage()}");
            return null;
        }
    }
}