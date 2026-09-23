<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Infrastructure\Wp;
use LuziApi\Shop\Domain\Customer\CustomerTimelineEntry;
use LuziApi\Shop\Domain\Customer\CustomerTimelineRepository;
use WC_Order;

final readonly class WooCommerceCustomerTimelineRepository implements CustomerTimelineRepository
{
    public function __construct(private DateTimeZone $timezone)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        $timeline = [];
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                continue;
            }
            $notes = wc_get_order_notes(['order_id' => $orderId, 'limit' => 100, 'orderby' => 'date_created', 'order' => 'DESC']);
            foreach ($notes as $note) {
                $date = $note->date_created ?? null;
                if (! $date instanceof \WC_DateTime) {
                    continue;
                }
                $timeline[] = new CustomerTimelineEntry(
                    $orderId,
                    $order->get_order_number(),
                    (new DateTimeImmutable('@' . $date->getTimestamp()))->setTimezone($this->timezone),
                    wp_strip_all_tags(Wp::str($note->content ?? '')),
                    (bool) ($note->customer_note ?? false),
                    Wp::str($note->added_by ?? 'Système'),
                );
            }
        }

        usort($timeline, static fn (CustomerTimelineEntry $left, CustomerTimelineEntry $right): int => $right->occurredAt <=> $left->occurredAt);

        return array_slice($timeline, 0, 100);
    }
}
