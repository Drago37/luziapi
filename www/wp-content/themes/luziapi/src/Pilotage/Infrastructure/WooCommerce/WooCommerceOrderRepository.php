<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use WC_Order;

final readonly class WooCommerceOrderRepository implements OrderRepository
{
    public function __construct(private DateTimeZone $timezone)
    {
    }

    public function createdBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $orders = wc_get_orders([
            'limit'        => -1,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
            'type'         => 'shop_order',
            'status'       => array_keys(wc_get_order_statuses()),
            'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
        ]);
        $snapshots = [];

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            $snapshot = $this->toSnapshot($order);
            if (null !== $snapshot) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    public function firstOrderDate(): ?DateTimeImmutable
    {
        $orders = wc_get_orders([
            'limit'   => 1,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
            'type'    => 'shop_order',
            'status'  => array_keys(wc_get_order_statuses()),
        ]);
        $order = $orders[0] ?? null;

        if (! $order instanceof WC_Order || ! $order->get_date_created()) {
            return null;
        }

        return $this->immutableDate($order->get_date_created()->getTimestamp());
    }

    private function toSnapshot(WC_Order $order): ?OrderSnapshot
    {
        $createdAt = $order->get_date_created();
        if (! $createdAt) {
            return null;
        }

        $source = function_exists('luziapi_order_source') ? luziapi_order_source($order) : '';
        $fulfillment = function_exists('luziapi_order_fulfillment_mode')
            ? luziapi_order_fulfillment_mode($order)
            : 'unknown';
        $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        return new OrderSnapshot(
            $order->get_id(),
            $order->get_order_number(),
            $this->immutableDate($createdAt->getTimestamp()),
            $order->get_status(),
            new Money($this->toCents($order->get_total())),
            new Money($this->toCents($order->get_total_refunded())),
            $order->get_item_count(),
            '' !== $customerName ? $customerName : 'Client de passage',
            trim($order->get_billing_email()),
            trim($order->get_billing_phone()),
            trim($order->get_billing_city()),
            $source,
            $fulfillment,
        );
    }

    private function immutableDate(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
    }

    /**
     * WooCommerce expose les totaux sous forme de chaînes décimales. La
     * conversion en centimes reste cantonnée à l'adaptateur.
     */
    private function toCents(string|float|int $amount): int
    {
        return (int) round((float) wc_format_decimal((string) $amount, 2) * 100);
    }
}
