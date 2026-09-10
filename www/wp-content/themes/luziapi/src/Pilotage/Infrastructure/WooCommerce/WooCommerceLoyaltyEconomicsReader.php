<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use WC_Order;

final readonly class WooCommerceLoyaltyEconomicsReader implements LoyaltyEconomicsReader
{
    public function __construct(private WooCommerceEligiblePotCounter $counter)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        $potsBought = 0;
        $offeredPots = 0;
        $discountCents = 0;
        foreach (array_unique($orderIds) as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order || 'completed' !== $order->get_status()) {
                continue;
            }
            $potsBought += $this->counter->countEligiblePots($order);
            $offeredPots += $this->counter->countOfferedPots($order);
            $discountCents += max(0, (int) $order->get_meta(WooCommerceThankYouDiscount::ORDER_META));
        }

        return ['potsBought' => $potsBought, 'offeredPots' => $offeredPots, 'discountCents' => $discountCents];
    }
}
