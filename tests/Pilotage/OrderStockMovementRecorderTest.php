<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Command\RecordOrderStockMovement\OrderStockMovementRecorder;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewHarvestLot;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;
use PHPUnit\Framework\TestCase;

final class OrderStockMovementRecorderTest extends TestCase
{
    public function testReductionIsIdempotentAndANewCycleWorksAfterRestoration(): void
    {
        $inventory = new OrderInventoryInMemory();
        $recorder = new OrderStockMovementRecorder($inventory, new InventoryClock());

        $recorder->recordReduction(10, 20, 30, 2);
        self::assertSame(3, $inventory->findLot(1)?->stockRemaining);

        $recorder->recordReduction(10, 20, 30, 2);
        self::assertCount(1, $inventory->orderMovements(10, 20, StockMovementType::OrderSale));

        $recorder->recordRestoration(10, 20);
        self::assertSame(5, $inventory->findLot(1)?->stockRemaining);

        $recorder->recordReduction(10, 20, 30, 2);
        self::assertSame(3, $inventory->findLot(1)?->stockRemaining);
        self::assertCount(2, $inventory->orderMovements(10, 20, StockMovementType::OrderSale));

        $recorder->recordRestoration(10, 20);
        self::assertSame(5, $inventory->findLot(1)?->stockRemaining);
        self::assertCount(2, $inventory->orderMovements(10, 20, StockMovementType::OrderRestoration));
    }

    public function testLegacyUnallocatedStockIsConsumedBeforeTrackedLots(): void
    {
        $inventory = new OrderInventoryInMemory();
        $recorder = new OrderStockMovementRecorder($inventory, new InventoryClock());

        $recorder->recordReduction(10, 20, 30, 2, 15);

        self::assertSame(5, $inventory->findLot(1)?->stockRemaining);
        $movements = $inventory->orderMovements(10, 20, StockMovementType::OrderSale);
        self::assertCount(1, $movements);
        self::assertNull($movements[0]->lotId);
        self::assertSame(-2, $movements[0]->quantityDelta);
    }

    public function testPreferredLotIsConsumedBeforeLegacyStock(): void
    {
        $inventory = new OrderInventoryInMemory();
        $recorder = new OrderStockMovementRecorder($inventory, new InventoryClock());

        $recorder->recordReduction(10, 20, 30, 2, 15, 1);

        self::assertSame(3, $inventory->findLot(1)?->stockRemaining);
        $movements = $inventory->orderMovements(10, 20, StockMovementType::OrderSale);
        self::assertCount(1, $movements);
        self::assertSame(1, $movements[0]->lotId);
    }
}

final class InventoryClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09 10:00:00', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}

final class OrderInventoryInMemory implements InventoryRepository
{
    /** @var list<StockMovement> */
    private array $movements = [];

    public function createLotWithInitialStock(NewHarvestLot $lot): HarvestLot
    {
        throw new \LogicException('Not used.');
    }

    public function findLot(int $id): ?HarvestLot
    {
        if (1 !== $id) {
            return null;
        }

        return new HarvestLot(
            1,
            'LOT-1',
            30,
            2026,
            new DateTimeImmutable('2026-06-15'),
            new DateTimeImmutable('2026-06-20 10:00:00'),
            'Luzillé',
            'Acacia',
            5,
            $this->lotStock(),
            false,
            1,
            new DateTimeImmutable('2026-06-20 10:00:00'),
        );
    }

    public function lots(): array
    {
        return [$this->findLot(1)];
    }

    public function availableLotsForProduct(int $productId): array
    {
        $lot = $this->findLot(1);

        return 30 === $productId && null !== $lot && $lot->stockRemaining > 0 ? [$lot] : [];
    }

    public function addMovement(NewStockMovement $movement): StockMovement
    {
        $stored = new StockMovement(
            count($this->movements) + 1,
            count($this->movements) + 1,
            $movement->productId,
            $movement->lotId,
            $movement->orderId,
            $movement->orderItemId,
            $movement->occurredAt,
            $movement->quantityDelta,
            $movement->type,
            $movement->reason,
            $movement->referenceKey,
            $movement->createdBy,
            $movement->createdAt,
        );
        $this->movements[] = $stored;

        return $stored;
    }

    public function hasMovementReference(string $referenceKey): bool
    {
        return [] !== array_filter($this->movements, static fn (StockMovement $movement): bool => $movement->referenceKey === $referenceKey);
    }

    public function orderMovements(int $orderId, int $orderItemId, StockMovementType $type): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (StockMovement $movement): bool => $movement->orderId === $orderId
                && $movement->orderItemId === $orderItemId
                && $movement->type === $type,
        ));
    }

    public function orderItemMovements(int $orderId, int $orderItemId): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (StockMovement $movement): bool => $movement->orderId === $orderId
                && $movement->orderItemId === $orderItemId,
        ));
    }

    public function recentMovements(int $limit = 100): array
    {
        return array_slice(array_reverse($this->movements), 0, $limit);
    }

    private function lotStock(): int
    {
        return 5 + array_sum(array_map(
            static fn (StockMovement $movement): int => 1 === $movement->lotId ? $movement->quantityDelta : 0,
            $this->movements,
        ));
    }
}
