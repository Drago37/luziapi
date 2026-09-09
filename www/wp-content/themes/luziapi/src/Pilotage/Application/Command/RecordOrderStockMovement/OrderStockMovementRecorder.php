<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordOrderStockMovement;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;

final readonly class OrderStockMovementRecorder
{
    public function __construct(
        private InventoryRepository $inventory,
        private Clock $clock,
    ) {
    }

    public function recordReduction(
        int $orderId,
        int $orderItemId,
        int $productId,
        int $quantity,
        ?int $stockBeforeReduction = null,
        ?int $preferredLotId = null,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $existing = $this->inventory->orderMovements($orderId, $orderItemId, StockMovementType::OrderSale);
        $alreadyRecorded = max(0, -array_sum(array_map(
            static fn ($movement): int => $movement->quantityDelta,
            $this->inventory->orderItemMovements($orderId, $orderItemId),
        )));
        $remaining = max(0, $quantity - $alreadyRecorded);
        if (0 === $remaining) {
            return;
        }

        $availableLots = $this->inventory->availableLotsForProduct($productId);
        $preferredLotIsAvailable = false;
        if (null !== $preferredLotId) {
            foreach ($availableLots as $index => $lot) {
                if ($lot->id !== $preferredLotId) {
                    continue;
                }
                unset($availableLots[$index]);
                array_unshift($availableLots, $lot);
                $preferredLotIsAvailable = true;
                break;
            }
        }
        $trackedStock = array_sum(array_map(static fn ($lot): int => $lot->stockRemaining, $availableLots));
        $unallocatedStock = null === $stockBeforeReduction ? 0 : max(0, $stockBeforeReduction - $trackedStock);
        $unallocated = $preferredLotIsAvailable ? 0 : min($remaining, $unallocatedStock);
        if ($unallocated > 0) {
            $this->addOrderMovement(
                $orderId,
                $orderItemId,
                $productId,
                null,
                -$unallocated,
                StockMovementType::OrderSale,
                sprintf('Vente sur stock ancien — commande n°%d', $orderId),
                sprintf('order:%d:item:%d:sale:%d:unallocated', $orderId, $orderItemId, count($existing) + 1),
            );
            $existing = $this->inventory->orderMovements($orderId, $orderItemId, StockMovementType::OrderSale);
            $remaining -= $unallocated;
        }

        foreach ($availableLots as $lot) {
            $allocated = min($remaining, $lot->stockRemaining);
            if ($allocated <= 0) {
                continue;
            }
            $this->addOrderMovement(
                $orderId,
                $orderItemId,
                $productId,
                $lot->id,
                -$allocated,
                StockMovementType::OrderSale,
                sprintf('Vente — commande n°%d', $orderId),
                sprintf('order:%d:item:%d:sale:%d:lot:%d', $orderId, $orderItemId, count($existing) + 1, $lot->id),
            );
            $existing = $this->inventory->orderMovements($orderId, $orderItemId, StockMovementType::OrderSale);
            $remaining -= $allocated;
            if (0 === $remaining) {
                break;
            }
        }

        if ($remaining > 0) {
            $this->addOrderMovement(
                $orderId,
                $orderItemId,
                $productId,
                null,
                -$remaining,
                StockMovementType::OrderSale,
                sprintf('Vente sans lot disponible — commande n°%d', $orderId),
                sprintf('order:%d:item:%d:sale:%d:unallocated-overflow', $orderId, $orderItemId, count($existing) + 1),
            );
        }
    }

    public function recordRestoration(int $orderId, int $orderItemId): void
    {
        foreach ($this->inventory->orderMovements($orderId, $orderItemId, StockMovementType::OrderSale) as $sale) {
            $reference = 'stock-restoration:' . $sale->id;
            if ($this->inventory->hasMovementReference($reference)) {
                continue;
            }
            $this->addOrderMovement(
                $orderId,
                $orderItemId,
                $sale->productId,
                $sale->lotId,
                abs($sale->quantityDelta),
                StockMovementType::OrderRestoration,
                sprintf('Restauration — commande n°%d', $orderId),
                $reference,
            );
        }
    }

    private function addOrderMovement(
        int $orderId,
        int $orderItemId,
        int $productId,
        ?int $lotId,
        int $delta,
        StockMovementType $type,
        string $reason,
        string $reference,
    ): void {
        if ($this->inventory->hasMovementReference($reference)) {
            return;
        }
        $now = $this->clock->now();
        $this->inventory->addMovement(new NewStockMovement(
            $productId,
            $lotId,
            $orderId,
            $orderItemId,
            $now,
            $delta,
            $type,
            $reason,
            $reference,
            0,
            $now,
        ));
    }
}
