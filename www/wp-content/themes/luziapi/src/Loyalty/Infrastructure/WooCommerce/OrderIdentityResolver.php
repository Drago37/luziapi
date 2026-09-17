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
     * Toutes les clés d'identité de la commande (e-mail ET téléphone présents), pour
     * relier ces identifiants comme appartenant au même client. `[]` si aucun contact.
     *
     * @return list<string>
     */
    public function keysFor(WC_Order $order): array;
}
