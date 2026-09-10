<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use WC_Order;

final readonly class WooCommerceLoyaltyEconomicsReader implements LoyaltyEconomicsReader
{
    private const IGNORED_STATUSES = ['cancelled', 'refunded', 'failed', 'trash'];

    public function __construct(private WooCommerceEligiblePotCounter $counter)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        $discountCents = 0;
        $offeredPots = 0;
        foreach (array_unique($orderIds) as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order || in_array($order->get_status(), self::IGNORED_STATUSES, true)) {
                continue;
            }
            $discountCents += max(0, (int) $order->get_meta(WooCommerceThankYouDiscount::ORDER_META));
            $offeredPots += $this->counter->countOfferedPots($order);
        }

        return ['discountCents' => $discountCents, 'offeredPots' => $offeredPots];
    }
}
