<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

/**
 * Remise de volume « −1 € par pot dès 2 pots ». Règle **unique et partagée** entre
 * le panier du site (`inc/shop.php`, fee de panier) et la Vente du pilotage
 * (`WooCommerceQuickSaleOrderWriter`, fee de commande) : les deux chemins la
 * calculent ici, pour qu'ils ne puissent jamais diverger.
 *
 * Sans rapport avec la fidélité (qui, elle, gère « 15 pots = 1 offert »).
 */
final class VolumeDiscount
{
    private const PER_ITEM_CENTS = 100; // 1 €
    private const MIN_ITEMS = 2;

    /**
     * Montant de la remise en centimes (valeur positive) pour un nombre de pots
     * achetés ; 0 sous le seuil de {@see self::MIN_ITEMS}.
     */
    public static function cents(int $paidJarCount): int
    {
        return $paidJarCount >= self::MIN_ITEMS ? $paidJarCount * self::PER_ITEM_CENTS : 0;
    }

    /** Libellé de la ligne de remise, identique côté site et côté Vente. */
    public static function label(int $paidJarCount): string
    {
        return sprintf('Remise (−1 € par pot dès 2 pots) × %d', $paidJarCount);
    }
}
