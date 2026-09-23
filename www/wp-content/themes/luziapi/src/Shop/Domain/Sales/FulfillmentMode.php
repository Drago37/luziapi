<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Sales;

/**
 * Mode de remise d'une commande (livraison locale ⇄ retrait sur rendez-vous) et
 * cohérence entre ce mode et les statuts intermédiaires du workflow.
 *
 * Les valeurs restent des chaînes pour coller au reste du workflow WooCommerce.
 */
final class FulfillmentMode
{
    public const DELIVERY = 'delivery';
    public const PICKUP = 'pickup';
    public const UNKNOWN = 'unknown';

    /**
     * Déduit le mode de remise des méthodes d'expédition portées par la commande.
     *
     * @param list<string> $shippingMethodIds identifiants de méthode, dans l'ordre
     */
    public static function fromShippingMethodIds(array $shippingMethodIds): string
    {
        foreach ($shippingMethodIds as $methodId) {
            if ('free_shipping' === $methodId) {
                return self::DELIVERY;
            }

            if ('local_pickup' === $methodId) {
                return self::PICKUP;
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Un statut intermédiaire de remise ne doit s'appliquer qu'au mode concerné :
     * « en cours de livraison » exclut le retrait, « prête au retrait » exclut la
     * livraison. Les autres statuts s'appliquent quel que soit le mode.
     */
    public static function statusMatches(string $status, string $mode): bool
    {
        if ('out_for_delivery' === $status) {
            return self::PICKUP !== $mode;
        }

        if ('ready_for_pickup' === $status) {
            return self::DELIVERY !== $mode;
        }

        return true;
    }
}
