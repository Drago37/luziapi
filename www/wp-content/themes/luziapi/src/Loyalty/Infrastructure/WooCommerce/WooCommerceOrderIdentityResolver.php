<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use WC_Order;

/**
 * Résout la clé d'identité fidélité d'une commande à partir de son e-mail et de
 * son téléphone de facturation, exactement comme le projecteur du Pilotage
 * (e-mail prioritaire, sinon téléphone normalisé). `null` si aucun contact.
 */
final readonly class WooCommerceOrderIdentityResolver implements OrderIdentityResolver
{
    public function resolve(WC_Order $order): ?string
    {
        $identity = LoyaltyIdentity::fromContact(
            (string) $order->get_billing_email(),
            (string) $order->get_billing_phone(),
        );

        return $identity?->key;
    }
}
