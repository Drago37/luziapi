<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewHarvestLot;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;

final readonly class AuditedInventoryRepository implements InventoryRepository
{
    public function __construct(
        private InventoryRepository $inner,
        private ActivityRecorder $activity,
    ) {
    }

    public function createLotWithInitialStock(NewHarvestLot $lot): HarvestLot
    {
        $stored = $this->inner->createLotWithInitialStock($lot);
        $this->activity->record(
            ActivityCategory::Harvest,
            $lot->stockAlreadyRecorded ? 'existing_stock_attached' : 'harvest_created',
            'harvest_lot',
            $stored->id,
            sprintf('Récolte enregistrée — lot %s', $stored->lotNumber),
            [
                'Produit' => (string) $stored->productId,
                'Date de récolte' => $stored->harvestedAt->format('d/m/Y'),
                'Date de mise en pots' => $stored->jarredAt->format('d/m/Y H:i'),
                'Quantité' => $stored->quantityJarred . ' pots',
                'Stock' => $lot->stockAlreadyRecorded ? 'Stock WooCommerce existant rattaché' : 'Stock WooCommerce augmenté',
            ],
            $lot->createdBy,
        );

        return $stored;
    }

    public function findLot(int $id): ?HarvestLot
    {
        return $this->inner->findLot($id);
    }

    public function lots(): array
    {
        return $this->inner->lots();
    }

    public function availableLotsForProduct(int $productId): array
    {
        return $this->inner->availableLotsForProduct($productId);
    }

    public function addMovement(NewStockMovement $movement): StockMovement
    {
        $stored = $this->inner->addMovement($movement);
        $this->activity->record(
            ActivityCategory::Stock,
            $stored->type->value,
            null !== $stored->orderId ? 'order' : 'stock_movement',
            $stored->orderId ?? $stored->id,
            $stored->type->label() . sprintf(' (%+d pot%s)', $stored->quantityDelta, abs($stored->quantityDelta) > 1 ? 's' : ''),
            array_filter([
                'Produit' => (string) $stored->productId,
                'Lot' => null !== $stored->lotId ? (string) $stored->lotId : 'Non affecté',
                'Commande' => null !== $stored->orderId ? (string) $stored->orderId : '',
                'Motif' => $stored->reason,
            ], static fn (string $value): bool => '' !== $value),
            $stored->createdBy,
        );

        return $stored;
    }

    public function hasMovementReference(string $referenceKey): bool
    {
        return $this->inner->hasMovementReference($referenceKey);
    }

    public function orderMovements(int $orderId, int $orderItemId, StockMovementType $type): array
    {
        return $this->inner->orderMovements($orderId, $orderItemId, $type);
    }

    public function orderItemMovements(int $orderId, int $orderItemId): array
    {
        return $this->inner->orderItemMovements($orderId, $orderItemId);
    }

    public function recentMovements(int $limit = 100): array
    {
        return $this->inner->recentMovements($limit);
    }
}
