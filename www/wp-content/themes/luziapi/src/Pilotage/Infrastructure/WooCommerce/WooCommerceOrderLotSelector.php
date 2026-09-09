<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use WC_Order_Factory;
use WC_Order_Item_Product;
use WC_Product;

final readonly class WooCommerceOrderLotSelector
{
    private const META_KEY = '_luziapi_stock_lot_id';

    public function __construct(
        private InventoryRepository $inventory,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_after_order_itemmeta', [$this, 'render'], 20, 3);
        add_action('woocommerce_saved_order_items', [$this, 'save'], 20, 2);
    }

    /**
     * @param mixed $item
     * @param mixed $product
     */
    public function render(int $itemId, $item, $product): void
    {
        if (! $item instanceof WC_Order_Item_Product || ! $product instanceof WC_Product || ! current_user_can('edit_shop_orders')) {
            return;
        }

        $activeAllocations = $this->activeAllocations($item->get_order_id(), $itemId);
        if ([] !== $activeAllocations) {
            $labels = [];
            foreach ($activeAllocations as $lotId => $quantity) {
                $lotLabel = 'Stock ancien / non affecté';
                if ($lotId > 0) {
                    $lot = $this->inventory->findLot($lotId);
                    if ($lot instanceof HarvestLot) {
                        $lotLabel = $lot->lotNumber;
                    }
                }
                $labels[] = sprintf('%s : %d pot%s', $lotLabel, $quantity, $quantity > 1 ? 's' : '');
            }
            echo '<div class="luziapi-order-item-lot"><strong>Lot réellement décompté</strong><br>'
                . esc_html(implode(' · ', $labels))
                . '<br><small>Le lot est figé tant que le stock de cette ligne est décompté.</small></div>';

            return;
        }

        $selected = absint($item->get_meta(self::META_KEY));
        $lots = $this->inventory->availableLotsForProduct($product->get_id());
        echo '<div class="luziapi-order-item-lot"><label><strong>Lot à utiliser</strong><br><select name="luziapi_stock_lot_id['
            . esc_attr((string) $itemId)
            . ']" style="max-width:100%;"><option value="">Automatique : ancien stock puis plus ancien lot</option>';
        foreach ($lots as $lot) {
            echo '<option value="'
                . esc_attr((string) $lot->id)
                . '" '
                . selected($selected, $lot->id, false)
                . '>'
                . esc_html(sprintf('%s — récolte %s — %d pots', $lot->lotNumber, $lot->harvestedAt->format('d/m/Y'), $lot->stockRemaining))
                . '</option>';
        }
        echo '</select></label><br><small>Facultatif. Enregistre les articles avant de faire évoluer le statut de la commande.</small></div>';
    }

    /** @param array<string, mixed> $items */
    public function save(int $orderId, array $items): void
    {
        if (! current_user_can('edit_shop_orders') || ! isset($items['luziapi_stock_lot_id']) || ! is_array($items['luziapi_stock_lot_id'])) {
            return;
        }

        foreach ($items['luziapi_stock_lot_id'] as $itemId => $rawLotId) {
            $item = WC_Order_Factory::get_order_item(absint($itemId));
            if (! $item instanceof WC_Order_Item_Product || $item->get_order_id() !== $orderId) {
                continue;
            }
            if ([] !== $this->activeAllocations($orderId, $item->get_id())) {
                continue;
            }

            $lotId = absint($rawLotId);
            $previousLotId = absint($item->get_meta(self::META_KEY));
            if (0 === $lotId) {
                $item->delete_meta_data(self::META_KEY);
                $item->save_meta_data();
                if ($previousLotId > 0) {
                    $this->recordSelection($orderId, $item, null);
                }

                continue;
            }

            $lot = $this->inventory->findLot($lotId);
            $product = $item->get_product();
            if (! $lot instanceof HarvestLot || ! $product instanceof WC_Product || $lot->productId !== $product->get_id() || $lot->stockRemaining <= 0) {
                continue;
            }

            $item->update_meta_data(self::META_KEY, (string) $lotId);
            $item->save_meta_data();
            if ($previousLotId !== $lotId) {
                $this->recordSelection($orderId, $item, $lot);
            }
        }
    }

    private function recordSelection(int $orderId, WC_Order_Item_Product $item, ?HarvestLot $lot): void
    {
        $order = wc_get_order($orderId);
        $this->activity->record(
            ActivityCategory::Order,
            'stock_lot_selected',
            'order',
            $orderId,
            sprintf('Lot de stock choisi pour la commande n°%s', $order ? $order->get_order_number() : $orderId),
            [
                'Produit' => $item->get_name(),
                'Lot' => $lot instanceof HarvestLot ? $lot->lotNumber : 'Affectation automatique',
            ],
            get_current_user_id(),
        );
    }

    /** @return array<int, int> Lot ID (0 means unallocated) to active quantity. */
    private function activeAllocations(int $orderId, int $orderItemId): array
    {
        $balances = [];
        foreach ($this->inventory->orderItemMovements($orderId, $orderItemId) as $movement) {
            $lotId = $movement->lotId ?? 0;
            $balances[$lotId] = ($balances[$lotId] ?? 0) + $movement->quantityDelta;
        }

        $active = [];
        foreach ($balances as $lotId => $balance) {
            if ($balance < 0) {
                $active[$lotId] = abs($balance);
            }
        }

        return $active;
    }
}
