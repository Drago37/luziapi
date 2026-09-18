<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount\AppliedVolumeDiscount;
use LuziApi\Pilotage\Application\Port\OrderVolumeDiscountWriter;
use LuziApi\Pilotage\Domain\Sales\VolumeDiscount;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Fee;

/**
 * Ajoute à une commande WooCommerce existante la part manquante de la remise de
 * volume, en fee négatif (même règle et libellé que la Vente / le panier).
 */
final class WooCommerceOrderVolumeDiscountWriter implements OrderVolumeDiscountWriter
{
    public function applyMissing(int $orderId): AppliedVolumeDiscount
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Order not found for volume discount catch-up.');
        }
        if (in_array($order->get_status(), ['cancelled', 'refunded', 'trash'], true)) {
            throw new RuntimeException('A volume discount cannot be applied to this order.');
        }

        $paidJars = self::paidJars($order);
        // La remise se compte en euros entiers (comme le site et la Vente) : on aligne
        // le montant appliqué sur le fee réellement posé, pour que la correction de
        // recette ne puisse jamais diverger de la commande.
        $appliedEuros = intdiv(self::missingCents($order), 100);

        if ($appliedEuros > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name(VolumeDiscount::label($paidJars));
            $fee->set_total((string) (-1 * $appliedEuros));
            $order->add_item($fee);
            $order->calculate_totals(false);
            $order->add_order_note(sprintf('Rattrapage remise de volume : %s.', $this->euros($appliedEuros * 100)), 0);
            $order->save();
        }

        return new AppliedVolumeDiscount(
            $orderId,
            $order->get_order_number(),
            $appliedEuros * 100,
            'bacs' === $order->get_payment_method() ? 'bank_transfer' : 'cash',
            $paidJars,
        );
    }

    /**
     * Pots PAYÉS de la commande : lignes produit hors offert / fidélité (0 €).
     * Source unique du décompte, réutilisée par la métabox et l'audit.
     */
    public static function paidJars(WC_Order $order): int
    {
        $paidJars = 0;
        foreach ($order->get_items() as $item) {
            $offered = 'yes' === $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META);
            $reward = 'yes' === $item->get_meta(WooCommerceEligiblePotCounter::REWARD_LINE_META);
            if (! $offered && ! $reward) {
                $paidJars += (int) $item->get_quantity();
            }
        }

        return $paidJars;
    }

    /**
     * Part de remise de volume MANQUANTE (en centimes, ≥ 0) : attendue moins déjà
     * présente sur la commande. 0 si la remise est déjà correcte.
     */
    public static function missingCents(WC_Order $order): int
    {
        $expected = VolumeDiscount::cents(self::paidJars($order));
        $existing = 0;
        foreach ($order->get_fees() as $fee) {
            if (str_contains((string) $fee->get_name(), 'par pot')) {
                $existing += abs((int) round((float) $fee->get_total() * 100));
            }
        }

        return max(0, $expected - $existing);
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
