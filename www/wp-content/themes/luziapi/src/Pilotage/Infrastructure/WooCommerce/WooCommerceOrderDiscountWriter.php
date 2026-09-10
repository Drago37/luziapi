<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\AppliedThankYouDiscount;
use LuziApi\Pilotage\Application\Port\OrderDiscountWriter;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use RuntimeException;
use WC_Order;

/**
 * Applique une remise remerciement sur une commande WooCommerce existante
 * (a posteriori), comme une vraie réduction (voir {@see WooCommerceThankYouDiscount}).
 */
final class WooCommerceOrderDiscountWriter implements OrderDiscountWriter
{
    public function apply(int $orderId, ThankYouDiscount $discount): AppliedThankYouDiscount
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Order not found for thank-you discount.');
        }
        if (in_array($order->get_status(), ['cancelled', 'refunded', 'trash'], true)) {
            throw new RuntimeException('A thank-you discount cannot be applied to this order.');
        }

        $discountCents = WooCommerceThankYouDiscount::applyTo($order, $discount);
        if ($discountCents > 0) {
            $order->calculate_totals(false);
            $order->add_order_note(sprintf('Remise remerciement de %s appliquée.', $this->euros($discountCents)), 0);
            $order->save();
        }

        return new AppliedThankYouDiscount(
            $orderId,
            $order->get_order_number(),
            $discountCents,
            'bacs' === $order->get_payment_method() ? 'bank_transfer' : 'cash',
        );
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
