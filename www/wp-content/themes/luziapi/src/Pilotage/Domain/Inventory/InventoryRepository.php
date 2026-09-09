<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Inventory;

interface InventoryRepository
{
    public function createLotWithInitialStock(NewHarvestLot $lot): HarvestLot;

    public function findLot(int $id): ?HarvestLot;

    /** @return list<HarvestLot> */
    public function lots(): array;

    /** @return list<HarvestLot> */
    public function availableLotsForProduct(int $productId): array;

    public function addMovement(NewStockMovement $movement): StockMovement;

    public function hasMovementReference(string $referenceKey): bool;

    /** @return list<StockMovement> */
    public function orderMovements(int $orderId, int $orderItemId, StockMovementType $type): array;

    /** @return list<StockMovement> */
    public function orderItemMovements(int $orderId, int $orderItemId): array;

    /** @return list<StockMovement> */
    public function recentMovements(int $limit = 100): array;
}
