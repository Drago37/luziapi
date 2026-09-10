<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use WC_Order;

/**
 * Règle métier LuziApi : une commande « Terminée » est encaissée. On enregistre
 * donc automatiquement sa recette au passage à ce statut — sauf pour les
 * commandes issues de la Vente, qui portent déjà leur propre encaissement.
 */
final readonly class WooCommerceReceiptSubscriber
{
    public function __construct(
        private RecordOrderReceiptHandler $handler,
        private Clock $clock,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_completed', [$this, 'orderCompleted'], 100, 2);
    }

    /** @param mixed $order */
    public function orderCompleted(int $orderId, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        // La Vente enregistre elle-même sa recette lors de la création.
        if ('yes' === $order->get_meta('_luziapi_quick_sale')) {
            return;
        }

        $expectedCents = (int) round(((float) $order->get_total() - (float) $order->get_total_refunded()) * 100);
        if ($expectedCents <= 0) {
            return;
        }

        $paidAt = $order->get_date_paid() ?: $order->get_date_completed();
        $occurredAt = $paidAt instanceof \WC_DateTime
            ? DateTimeImmutable::createFromInterface($paidAt)->setTimezone($this->clock->timezone())
            : $this->clock->now();

        $this->handler->handle(new RecordOrderReceiptCommand(
            $orderId,
            $expectedCents,
            'bacs' === $order->get_payment_method() ? 'bank_transfer' : 'cash',
            $occurredAt,
            current_user_can('edit_shop_orders') ? get_current_user_id() : 0,
            sprintf('Encaissement — commande n°%s', $order->get_order_number()),
        ));
    }
}
