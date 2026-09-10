<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Application\Port\OrderContactKeys;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use WC_Order;

final readonly class WooCommerceOrderContactKeys implements OrderContactKeys
{
    public function forOrderIds(array $orderIds): array
    {
        $keys = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                continue;
            }
            foreach (LoyaltyIdentity::keysForContact((string) $order->get_billing_email(), (string) $order->get_billing_phone()) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
