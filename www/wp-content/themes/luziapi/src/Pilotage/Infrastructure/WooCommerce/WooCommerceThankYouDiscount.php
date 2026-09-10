<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Applique une remise remerciement comme une **vraie réduction** WooCommerce
 * (et non des frais négatifs) : on baisse le total des lignes payées en gardant
 * leur sous-total, si bien que `calculate_totals()` renseigne le `discount_total`
 * natif de la commande. La remise s'affiche donc comme une réduction sur la
 * commande et les e-mails.
 *
 * Ne touche pas aux quantités (donc au stock ni au décompte fidélité) ni aux
 * lignes offertes (déjà à 0 €). Ne fait pas `calculate_totals()`/`save()` :
 * c'est à l'appelant de recalculer puis d'enregistrer.
 */
final class WooCommerceThankYouDiscount
{
    public const ORDER_META = '_luziapi_thankyou_discount_cents';

    /**
     * Réduit les lignes payées et renvoie le montant réellement remisé (centimes).
     */
    public static function applyTo(WC_Order $order, ThankYouDiscount $discount): int
    {
        /** @var list<array{item: WC_Order_Item_Product, cents: int}> $paid */
        $paid = [];
        $base = 0;
        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ('yes' === (string) $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META)) {
                continue; // déjà offerte
            }
            $cents = (int) round((float) $item->get_total() * 100);
            if ($cents <= 0) {
                continue;
            }
            $paid[] = ['item' => $item, 'cents' => $cents];
            $base += $cents;
        }

        $discountCents = $discount->computeCents($base);
        if ($discountCents <= 0 || [] === $paid) {
            return 0;
        }

        // Répartit la remise au prorata du total de chaque ligne ; le reliquat
        // d'arrondi tombe sur la dernière ligne. Le total de chaque ligne est
        // arrondi au pas de la devise (WooCommerce le ferait de toute façon), et
        // on renvoie la remise RÉELLEMENT appliquée pour que la correction de
        // recette colle exactement, quelle que soit la précision de la devise.
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $realized = 0;
        $remaining = $discountCents;
        $lastIndex = count($paid) - 1;
        foreach ($paid as $index => $line) {
            $reduction = $index === $lastIndex
                ? $remaining
                : min($remaining, (int) round($discountCents * $line['cents'] / $base));
            $remaining -= $reduction;
            $newEuros = round(max(0, $line['cents'] - $reduction) / 100, $decimals);
            $line['item']->set_total((string) $newEuros);
            $realized += $line['cents'] - (int) round($newEuros * 100);
        }
        if ($realized <= 0) {
            return 0;
        }

        $previous = (int) $order->get_meta(self::ORDER_META);
        $order->update_meta_data(self::ORDER_META, (string) ($previous + $realized));

        return $realized;
    }
}
