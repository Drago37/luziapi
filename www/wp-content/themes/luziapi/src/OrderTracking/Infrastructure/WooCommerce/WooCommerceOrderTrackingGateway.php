<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WooCommerce;

use DateTimeImmutable;
use LuziApi\OrderTracking\Application\Port\OrderTrackingGateway;
use LuziApi\OrderTracking\Application\View\PublicOrderLine;
use LuziApi\OrderTracking\Application\View\PublicOrderPage;
use LuziApi\OrderTracking\Application\View\PublicOrderTotal;
use LuziApi\OrderTracking\Application\View\PublicOrderUpdate;
use LuziApi\OrderTracking\Application\View\PublicOrderView;
use LuziApi\OrderTracking\Domain\PublicOrderStatus;
use LuziApi\OrderTracking\Domain\StatusHistoryRepository;
use LuziApi\OrderTracking\Domain\StatusTransition;

final readonly class WooCommerceOrderTrackingGateway implements OrderTrackingGateway
{
    public function __construct(private StatusHistoryRepository $statusHistory)
    {
    }

    public function findOrderId(string $orderNumber, string $email): ?int
    {
        $order = wc_get_order((int) $orderNumber);
        if (! $order instanceof \WC_Order
            || ! hash_equals(mb_strtolower(trim((string) $order->get_billing_email())), mb_strtolower(trim($email)))) {
            return null;
        }

        return $order->get_id();
    }

    public function findOrderIdsByEmail(string $email): array
    {
        $ids = wc_get_orders([
            'customer' => mb_strtolower(trim($email)),
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
        ]);

        $orderIds = [];
        foreach (is_array($ids) ? $ids : [] as $orderOrId) {
            $orderId = $orderOrId instanceof \WC_Order ? $orderOrId->get_id() : (int) $orderOrId;
            if ($orderId > 0) {
                $orderIds[] = $orderId;
            }
        }

        return array_values(array_unique($orderIds));
    }

    public function getOrders(array $allowedOrderIds, int $page, int $perPage, string $selectedOrderNumber): PublicOrderPage
    {
        $allowedOrderIds = array_values(array_unique(array_filter($allowedOrderIds, static fn (int $id): bool => $id > 0)));
        $orders = wc_get_orders([
            'include' => $allowedOrderIds,
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $orders = array_values(array_filter(
            is_array($orders) ? $orders : [],
            static fn ($order): bool => $order instanceof \WC_Order && in_array($order->get_id(), $allowedOrderIds, true),
        ));
        $history = $this->statusHistory->forOrderIds(array_map(static fn (\WC_Order $order): int => $order->get_id(), $orders));
        $views = array_map(fn (\WC_Order $order): PublicOrderView => $this->mapOrder($order, $history[$order->get_id()] ?? []), $orders);

        $selected = null;
        if ('' !== $selectedOrderNumber) {
            foreach ($views as $view) {
                if (hash_equals($view->number, ltrim($selectedOrderNumber, '#'))) {
                    $selected = $view;
                    break;
                }
            }
        }
        if (null === $selected && 1 === count($views)) {
            $selected = $views[0];
        }

        $perPage = max(1, min(50, $perPage));
        $totalOrders = count($views);
        $totalPages = max(1, (int) ceil($totalOrders / $perPage));
        $page = min(max(1, $page), $totalPages);

        return new PublicOrderPage(
            array_slice($views, ($page - 1) * $perPage, $perPage),
            $page,
            $totalPages,
            $totalOrders,
            $selected,
        );
    }

    /** @param list<StatusTransition> $transitions */
    private function mapOrder(\WC_Order $order, array $transitions): PublicOrderView
    {
        $created = $this->date($order->get_date_created());
        $updates = [new PublicOrderUpdate('status', 'Commande reçue', 'Votre commande a bien été enregistrée.', $created)];
        foreach ($transitions as $transition) {
            $status = PublicOrderStatus::describe($transition->toStatus);
            $updates[] = new PublicOrderUpdate('status', $status['label'], '', $transition->occurredAt);
        }
        foreach ($order->get_customer_order_notes() as $note) {
            $updates[] = new PublicOrderUpdate(
                'note',
                'Message de LuziApi',
                trim((string) $note->comment_content),
                new DateTimeImmutable((string) $note->comment_date, wp_timezone()),
            );
        }
        if ([] === $transitions) {
            $status = PublicOrderStatus::describe($order->get_status());
            if ('Commande reçue' !== $status['label']) {
                $updates[] = new PublicOrderUpdate(
                    'status',
                    $status['label'],
                    'État actuel — l’historique antérieur n’est pas disponible.',
                    $this->date($order->get_date_modified()),
                );
            }
        }
        usort($updates, static fn (PublicOrderUpdate $left, PublicOrderUpdate $right): int => $right->occurredAt <=> $left->occurredAt);

        $lines = [];
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $lines[] = new PublicOrderLine(
                (string) $item->get_name(),
                (int) $item->get_quantity(),
                $this->cents((string) $item->get_total()),
            );
        }

        $totals = [new PublicOrderTotal('Sous-total', $this->cents((string) $order->get_subtotal()))];
        $discount = $this->cents((string) $order->get_discount_total());
        if ($discount > 0) {
            $totals[] = new PublicOrderTotal('Remises', -$discount);
        }
        foreach ($order->get_fees() as $fee) {
            $totals[] = new PublicOrderTotal((string) $fee->get_name(), $this->cents((string) $fee->get_total()));
        }
        $shipping = $this->cents((string) $order->get_shipping_total());
        if (0 !== $shipping) {
            $totals[] = new PublicOrderTotal('Livraison', $shipping);
        }
        $totals[] = new PublicOrderTotal('Total', $this->cents((string) $order->get_total()));

        $fulfillmentMode = function_exists('luziapi_order_fulfillment_mode')
            ? luziapi_order_fulfillment_mode($order)
            : 'unknown';

        return new PublicOrderView(
            $order->get_id(),
            (string) $order->get_order_number(),
            trim((string) $order->get_billing_first_name()),
            $created,
            $order->get_status(),
            $this->cents((string) $order->get_total()),
            $order->get_currency(),
            trim((string) $order->get_payment_method_title()),
            $fulfillmentMode,
            match ($fulfillmentMode) {
                'delivery' => 'Livraison gratuite à Luzillé ou Bléré',
                'pickup' => 'Retrait au domicile de LuziApi à Luzillé',
                default => 'À convenir avec LuziApi',
            },
            trim((string) $order->get_customer_note()),
            $lines,
            $totals,
            $updates,
        );
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /** @param \WC_DateTime|false|null $date */
    private function date($date): DateTimeImmutable
    {
        $timestamp = $date instanceof \WC_DateTime ? $date->getTimestamp() : time();

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone());
    }
}
