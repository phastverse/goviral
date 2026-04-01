<?php

namespace App\Services;

use App\Models\Reseller;

class ResellerPricingService
{
    /**
     * Get the price the end-customer pays for a given service on a reseller panel.
     * Rate from Ogaviral is per 1000 units.
     *
     * @param  float    $ogaviralRate  Raw rate from Ogaviral API (per 1000)
     * @param  int      $serviceId     Ogaviral service ID
     * @param  int      $quantity      Number of units ordered
     * @param  Reseller $reseller      The reseller context
     * @return float                   Total charge in NGN
     */
    public static function calculateCharge(
        float $ogaviralRate,
        int $serviceId,
        int $quantity,
        Reseller $reseller
    ): float {
        // Apply your platform markup first (your cost to the reseller)
        $yourCost = \App\Services\PricingService::calculatePrice($ogaviralRate, '');

        // Then apply the reseller's markup on top of your marked-up price
        $resellerRate = $reseller->priceForService($yourCost, $serviceId);

        return round(($quantity / 1000) * $resellerRate, 2);
    }

    /**
     * What your platform charges the reseller's wallet (at YOUR markup, not theirs).
     */
    public static function calculateYourCost(
        float $ogaviralRate,
        string $serviceName,
        int $quantity
    ): float {
        $markedUpRate = \App\Services\PricingService::calculatePrice($ogaviralRate, $serviceName);
        return round(($quantity / 1000) * $markedUpRate, 2);
    }
}
