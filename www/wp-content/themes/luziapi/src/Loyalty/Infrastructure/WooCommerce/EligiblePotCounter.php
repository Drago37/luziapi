<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use WC_Order;

/**
 * Compte, sur une commande, les pots qui pèsent sur la fidélité.
 *
 * Couture testable pour {@see WooCommerceLoyaltyEarningSubscriber} : n'expose que
 * ce que la réconciliation consomme (pots éligibles + avantages consommés).
 * L'implémentation de production est {@see WooCommerceEligiblePotCounter}.
 */
interface EligiblePotCounter
{
    public function countEligiblePots(WC_Order $order): int;

    public function countRewardPots(WC_Order $order): int;
}
