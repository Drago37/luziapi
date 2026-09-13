<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Ajoute à une commande une ligne **offerte** (0 €) : geste commercial
 * (`$loyalty = false`) ou pot offert au titre de la fidélité (`$loyalty = true`).
 *
 * La ligne est marquée pour être exclue du gain de pots et, pour la fidélité,
 * décompter un avantage ; une méta visible « Offert » l'affiche sur la commande et
 * les e-mails. Comme le total de la ligne est à 0 €, le **montant de la commande
 * ne change pas** — la recette éventuellement déjà encaissée reste intacte.
 *
 * Le décompte du stock n'est **pas** fait ici : il appartient à l'appelant, qui
 * sait s'il crée une commande neuve (décompte global) ou s'il ajoute une ligne à
 * une commande existante (décompte ciblé du seul nouvel item).
 */
final class OfferedOrderItem
{
    public static function addTo(WC_Order $order, WC_Product $product, int $quantity, bool $loyalty): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product();
        $item->set_product($product);
        $item->set_quantity($quantity);
        $item->set_subtotal('0');
        $item->set_total('0');
        $item->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
        $item->add_meta_data('Offert', $loyalty ? 'Fidélité' : 'Oui', true);
        if ($loyalty) {
            $item->add_meta_data(WooCommerceEligiblePotCounter::REWARD_LINE_META, 'yes', true);
        }
        $order->add_item($item);

        return $item;
    }
}
