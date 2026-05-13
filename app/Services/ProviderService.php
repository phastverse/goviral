<?php

namespace App\Services;

use App\Models\Provider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ProviderService
 *
 * Responsible for:
 *  - Loading active providers from the DB (ordered by priority)
 *  - Randomly selecting one from the top-priority tier
 *  - Falling back through the list when a provider fails
 *  - Exposing a clean interface used by OrderController
 *
 * Usage in OrderController (drop-in replacement for OgaviralService):
 *
 *   $result = $this->providerService->placeOrder($serviceId, $link, $quantity);
 *   // $result['response']  => the raw API array
 *   // $result['provider']  => the Provider model that succeeded
 */
class ProviderService
{
    // ─── Public: Service catalogue ────────────────────────────────────────────

    /**
     * Fetch services from ONE available provider.
     * Returns ['services' => [...], 'provider' => Provider].
     */
    public function getServices(): array
    {
        $provider = $this->pickRandom();

        if (!$provider) {
            Log::error('ProviderService: No active providers available for getServices()');
            return [];
        }

        $api = new ProviderApiService($provider);
        return $api->getServices();
    }

    /**
     * Fetch services along with which provider supplied them.
     */
    public function getServicesWithProvider(): array
    {
        $provider = $this->pickRandom();

        if (!$provider) {
            return ['services' => [], 'provider' => null];
        }

        $api = new ProviderApiService($provider);
        return [
            'services' => $api->getServices(),
            'provider' => $provider,
        ];
    }

    // ─── Public: Order placement with fallback ────────────────────────────────

    /**
     * Place an order, trying providers in random-within-priority order.
     * Falls back automatically if one fails.
     *
     * Returns:
     *   [
     *     'response'  => array,    // raw API response
     *     'provider'  => Provider, // the provider that succeeded
     *     'attempted' => int,      // how many providers were tried
     *   ]
     * or null if all providers fail.
     */
    public function placeOrder(int $serviceId, string $link, int $quantity): ?array
    {
        $providers = $this->getOrderedProviders();

        if ($providers->isEmpty()) {
            Log::error('ProviderService: No active providers to place order.');
            return null;
        }

        $attempted = 0;

        foreach ($providers as $provider) {
            $attempted++;
            $api = new ProviderApiService($provider);

            Log::info("ProviderService: Trying [{$provider->name}] for order (attempt {$attempted})");

            try {
                $response = $api->placeOrder($serviceId, $link, $quantity);

                // Success = API returned a numeric order ID
                if (isset($response['order']) && is_numeric($response['order'])) {
                    Log::info("ProviderService: Order placed via [{$provider->name}]", [
                        'api_order_id' => $response['order'],
                        'attempt'      => $attempted,
                    ]);

                    return [
                        'response'  => $response,
                        'provider'  => $provider,
                        'attempted' => $attempted,
                    ];
                }

                // Provider returned an error – log and try the next one
                $error = $response['error'] ?? 'Unknown error';
                Log::warning("ProviderService: [{$provider->name}] rejected order: {$error}");

            } catch (\Exception $e) {
                Log::error("ProviderService: [{$provider->name}] threw exception: " . $e->getMessage());
            }
        }

        Log::error("ProviderService: All {$attempted} provider(s) failed for order.", [
            'service_id' => $serviceId,
            'link'       => $link,
            'quantity'   => $quantity,
        ]);

        return null;
    }

    // ─── Public: Status & refill (delegate to specific provider) ─────────────

    /**
     * Get order status from the provider that handled the order.
     * Falls back to any available provider if the original isn't found.
     */
    public function getOrderStatus(string $apiOrderId, ?string $providerId = null): array
    {
        $provider = $this->resolveProvider($providerId);

        if (!$provider) {
            return ['error' => 'No provider available to check status'];
        }

        $api = new ProviderApiService($provider);
        return $api->getOrderStatus($apiOrderId);
    }

    /**
     * Request a refill via the provider that originally handled the order.
     */
    public function createRefill(string $apiOrderId, ?string $providerId = null): array
    {
        $provider = $this->resolveProvider($providerId);

        if (!$provider) {
            return ['error' => 'No provider available to create refill'];
        }

        $api = new ProviderApiService($provider);
        return $api->createRefill($apiOrderId);
    }

    // ─── Public: Balance refresh (admin use) ──────────────────────────────────

    /**
     * Refresh and return the balance for a single provider.
     */
    public function refreshBalance(Provider $provider): ?float
    {
        $api = new ProviderApiService($provider);
        return $api->getBalance();
    }

    /**
     * Refresh balances for ALL active providers.
     * Returns a keyed array: ['ProviderName' => 1234.56, ...]
     */
    public function refreshAllBalances(): array
    {
        $results = [];

        foreach (Provider::active()->get() as $provider) {
            $results[$provider->name] = $this->refreshBalance($provider);
        }

        return $results;
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Return active providers ordered by priority (asc).
     * Within the same priority tier, shuffle for randomness.
     */
    protected function getOrderedProviders(): Collection
    {
        $all = Provider::active()->byPriority()->get();

        if ($all->isEmpty()) return $all;

        // Group by priority, shuffle within each group, then flatten
        return $all
            ->groupBy('priority')
            ->map(fn($group) => $group->shuffle())
            ->flatten();
    }

    /**
     * Pick ONE random provider from the highest (lowest number) priority tier.
     */
    protected function pickRandom(): ?Provider
    {
        $providers = Provider::active()->byPriority()->get();

        if ($providers->isEmpty()) return null;

        // Get the top priority value, collect all at that level, pick one at random
        $topPriority = $providers->first()->priority;

        return $providers
            ->filter(fn($p) => $p->priority === $topPriority)
            ->random();
    }

    /**
     * Find a provider by ID, or fall back to any active provider.
     */
    protected function resolveProvider(?string $providerId): ?Provider
    {
        if ($providerId) {
            $provider = Provider::active()->find($providerId);
            if ($provider) return $provider;
        }

        // Fallback: pick any active provider
        return Provider::active()->byPriority()->first();
    }
}