<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Shop\Application\Port\EligiblePotReader;
use WC_Order;

/**
 * Adaptateur du port {@see EligiblePotReader} : compte les pots admissibles d'une
 * commande via le compteur du module Loyalty (même règle que le crédit réel).
 */
final readonly class WooCommerceEligiblePotReader implements EligiblePotReader
{
    public function __construct(
        private WooCommerceEligiblePotCounter $counter,
    ) {
    }

    public function eligiblePotsByOrderIds(array $orderIds): array
    {
        $pots = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            $pots[$orderId] = $order instanceof WC_Order ? $this->counter->countEligiblePots($order) : 0;
        }

        return $pots;
    }
}
