<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\RecordOrderStockMovement\OrderStockMovementRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;

final readonly class WooCommerceOrderStockSubscriber
{
    public function __construct(
        private OrderStockMovementRecorder $recorder,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_reduce_order_item_stock', [$this, 'recordReduction'], 10, 3);
        add_action('woocommerce_restore_order_item_stock', [$this, 'recordRestoration'], 10, 4);
    }

    /** @param array{from?: int|float, to?: int|float} $change */
    public function recordReduction(WC_Order_Item_Product $item, array $change, WC_Order $order): void
    {
        $product = $item->get_product();
        $quantity = (int) round((float) ($change['from'] ?? 0) - (float) ($change['to'] ?? 0));
        if (! $product || $quantity <= 0) {
            return;
        }

        try {
            $this->recorder->recordReduction(
                $order->get_id(),
                $item->get_id(),
                $product->get_id(),
                $quantity,
                (int) round((float) ($change['from'] ?? 0)),
                absint($item->get_meta('_luziapi_stock_lot_id')) ?: null,
            );
        } catch (Throwable) {
            $order->add_order_note('Le mouvement de stock LuziApi n’a pas pu être journalisé. Vérifier Stocks et lots.', 0);
            $this->recordFailure($order, 'Décompte du stock non journalisé');
        }
    }

    public function recordRestoration(
        WC_Order_Item_Product $item,
        int|float $newStock,
        int|float $oldStock,
        WC_Order $order,
    ): void {
        if ($newStock <= $oldStock) {
            return;
        }

        try {
            $this->recorder->recordRestoration($order->get_id(), $item->get_id());
        } catch (Throwable) {
            $order->add_order_note('La restauration du lot LuziApi n’a pas pu être journalisée. Vérifier Stocks et lots.', 0);
            $this->recordFailure($order, 'Restauration du stock non journalisée');
        }
    }

    private function recordFailure(WC_Order $order, string $summary): void
    {
        $this->activity->record(
            ActivityCategory::Error,
            'order_stock_journal_failed',
            'order',
            $order->get_id(),
            $summary . ' — commande n°' . $order->get_order_number(),
        );
    }
}
