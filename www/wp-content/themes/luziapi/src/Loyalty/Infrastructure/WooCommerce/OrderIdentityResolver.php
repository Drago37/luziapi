<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use WC_Order;

/**
 * Résout la clé d'identité fidélité d'une commande (`null` si aucun contact).
 *
 * Couture testable pour {@see WooCommerceLoyaltyEarningSubscriber} ; implémentée
 * en production par {@see WooCommerceOrderIdentityResolver}.
 */
interface OrderIdentityResolver
{
    public function resolve(WC_Order $order): ?string;

    /**
     * Clés d'identité **typées** de la commande (e-mail et téléphone séparément, `null`
     * si absent), pour l'auto-liaison prudente qui doit les distinguer.
     *
     * @return array{email: ?string, phone: ?string}
     */
    public function contactKeys(WC_Order $order): array;
}
