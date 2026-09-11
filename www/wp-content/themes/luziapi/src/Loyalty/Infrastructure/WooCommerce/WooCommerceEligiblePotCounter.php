<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Compte les pots éligibles à la fidélité sur une commande.
 *
 * Est éligible tout produit portant la méta `_luziapi_pot_admissible = yes`
 * (case cochée dans la fiche produit). Le décompte :
 * - somme les quantités de ces produits (variation puis produit parent) ;
 * - déduit les quantités remboursées (remboursement partiel = moins de pots) ;
 * - exclut toute ligne **offerte** (méta de ligne `_luziapi_offert`), qu'il
 *   s'agisse d'un geste commercial ou d'un pot offert au titre de la fidélité :
 *   on ne gagne jamais un pot en recevant un pot gratuit.
 *
 * Le comptage des pots offerts **au titre de la fidélité** (méta de ligne
 * `_luziapi_loyalty_reward`, qui portent aussi `_luziapi_offert`) est fourni
 * séparément par `countRewardPots()` : ils consomment un avantage.
 */
final readonly class WooCommerceEligiblePotCounter implements EligiblePotCounter
{
    public const PRODUCT_ELIGIBLE_META = '_luziapi_pot_admissible';
    /** Toute ligne offerte (geste libre OU fidélité) — exclue du gain de pots. */
    public const OFFERT_LINE_META = '_luziapi_offert';
    /** Ligne offerte financée par un avantage fidélité — consomme un avantage. */
    public const REWARD_LINE_META = '_luziapi_loyalty_reward';

    public function countEligiblePots(WC_Order $order): int
    {
        $total = 0;
        foreach ($order->get_items() as $itemId => $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ($this->isOffered($item)) {
                continue; // ligne offerte : ne crédite pas de pot
            }
            if (! $this->isEligibleProduct($item)) {
                continue;
            }

            // Quantité nette = commandée + remboursée (get_qty_refunded... < 0).
            $netQuantity = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item((int) $itemId);
            if ($netQuantity > 0) {
                $total += $netQuantity;
            }
        }

        return $total;
    }

    /**
     * Nombre de pots offerts **au titre de la fidélité** sur la commande (chacun
     * consomme un avantage), ajusté des remboursements.
     */
    public function countRewardPots(WC_Order $order): int
    {
        return $this->countMarkedLines($order, self::REWARD_LINE_META);
    }

    /**
     * Nombre total de pots **offerts** sur la commande (geste commercial ET
     * fidélité), ajusté des remboursements.
     */
    public function countOfferedPots(WC_Order $order): int
    {
        return $this->countMarkedLines($order, self::OFFERT_LINE_META);
    }

    private function countMarkedLines(WC_Order $order, string $meta): int
    {
        $total = 0;
        foreach ($order->get_items() as $itemId => $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ('yes' !== (string) $item->get_meta($meta)) {
                continue;
            }
            $netQuantity = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item((int) $itemId);
            if ($netQuantity > 0) {
                $total += $netQuantity;
            }
        }

        return $total;
    }

    private function isOffered(WC_Order_Item_Product $item): bool
    {
        return 'yes' === (string) $item->get_meta(self::OFFERT_LINE_META)
            || 'yes' === (string) $item->get_meta(self::REWARD_LINE_META);
    }

    private function isEligibleProduct(WC_Order_Item_Product $item): bool
    {
        $product = $item->get_product();
        if (! $product instanceof \WC_Product) {
            return false;
        }

        // Variation : la méta peut vivre sur la variation ou sur le parent.
        if ('yes' === $product->get_meta(self::PRODUCT_ELIGIBLE_META, true)) {
            return true;
        }
        $parentId = $product->get_parent_id();
        if ($parentId > 0) {
            $parent = wc_get_product($parentId);
            if ($parent instanceof \WC_Product && 'yes' === $parent->get_meta(self::PRODUCT_ELIGIBLE_META, true)) {
                return true;
            }
        }

        return false;
    }
}
