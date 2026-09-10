<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\CreateHarvestLot\CreateHarvestLotCommand;
use LuziApi\Pilotage\Application\Command\CreateHarvestLot\CreateHarvestLotHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\StockLevelGateway;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewHarvestLot;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;
use LuziApi\Pilotage\Domain\Product\ProductCatalog;
use LuziApi\Pilotage\Domain\Product\ProductStockSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class CreateHarvestLotHandlerTest extends TestCase
{
    public function testExistingStockIsAttachedWithoutChangingWooCommerceStock(): void
    {
        $inventory = new HarvestInventoryInMemory(3);
        $stock = new HarvestStockGatewaySpy();
        $handler = new CreateHarvestLotHandler(
            $inventory,
            new HarvestProductCatalog(10),
            $stock,
            new HarvestClock(),
        );

        $lot = $handler->handle($this->command(7, true));

        self::assertSame([], $stock->adjustments);
        self::assertTrue($lot->stockAlreadyRecorded);
        self::assertSame(7, $lot->stockRemaining);
    }

    public function testExistingStockCannotExceedWooCommerceStockNotYetAttachedToALot(): void
    {
        $stock = new HarvestStockGatewaySpy();
        $handler = new CreateHarvestLotHandler(
            new HarvestInventoryInMemory(4),
            new HarvestProductCatalog(10),
            $stock,
            new HarvestClock(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Existing stock quantity exceeds unallocated WooCommerce stock.');

        try {
            $handler->handle($this->command(7, true));
        } finally {
            self::assertSame([], $stock->adjustments);
        }
    }

    public function testNewHarvestAddsItsQuantityToWooCommerceStock(): void
    {
        $stock = new HarvestStockGatewaySpy();
        $handler = new CreateHarvestLotHandler(
            new HarvestInventoryInMemory(3),
            new HarvestProductCatalog(10),
            $stock,
            new HarvestClock(),
        );

        $lot = $handler->handle($this->command(12, false));

        self::assertSame([[42, 12]], $stock->adjustments);
        self::assertFalse($lot->stockAlreadyRecorded);
    }

    public function testJarredLaterTodayIsAccepted(): void
    {
        // Horloge à 2026-09-09 10:00 ; une mise en pots le même jour à 23:00 (heure
        // « future » à la minute mais bien aujourd'hui) doit passer.
        $handler = new CreateHarvestLotHandler(
            new HarvestInventoryInMemory(3),
            new HarvestProductCatalog(10),
            new HarvestStockGatewaySpy(),
            new HarvestClock(),
        );

        $lot = $handler->handle(new CreateHarvestLotCommand(
            'L2026-TODAY',
            42,
            new DateTimeImmutable('2026-09-09', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-09-09 23:00:00', new DateTimeZone('Europe/Paris')),
            'Luzillé',
            'Été',
            6,
            false,
            1,
        ));

        self::assertSame(6, $lot->quantityJarred);
    }

    public function testJarredOnAFutureDayIsRejected(): void
    {
        $handler = new CreateHarvestLotHandler(
            new HarvestInventoryInMemory(3),
            new HarvestProductCatalog(10),
            new HarvestStockGatewaySpy(),
            new HarvestClock(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jar date cannot be in the future.');

        $handler->handle(new CreateHarvestLotCommand(
            'L2026-FUTURE',
            42,
            new DateTimeImmutable('2026-09-09', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-09-10 08:00:00', new DateTimeZone('Europe/Paris')),
            'Luzillé',
            'Été',
            6,
            false,
            1,
        ));
    }

    private function command(int $quantity, bool $stockAlreadyRecorded): CreateHarvestLotCommand
    {
        return new CreateHarvestLotCommand(
            'L2026-TEST',
            42,
            new DateTimeImmutable('2026-06-15'),
            new DateTimeImmutable('2026-06-20 10:00:00'),
            'Luzillé',
            'Printemps',
            $quantity,
            $stockAlreadyRecorded,
            1,
        );
    }
}

final class HarvestClock implements Clock
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

final readonly class HarvestProductCatalog implements ProductCatalog
{
    public function __construct(private int $stock)
    {
    }

    public function all(): array
    {
        return [new ProductStockSnapshot(42, 'Miel 1 kg', 'MIEL-1KG', $this->stock, 2, true, new Money(1200))];
    }
}

final class HarvestStockGatewaySpy implements StockLevelGateway
{
    /** @var list<array{int, int}> */
    public array $adjustments = [];

    public function adjust(int $productId, int $quantityDelta): void
    {
        $this->adjustments[] = [$productId, $quantityDelta];
    }
}

final class HarvestInventoryInMemory implements InventoryRepository
{
    private ?HarvestLot $created = null;

    public function __construct(private readonly int $trackedStock)
    {
    }

    public function createLotWithInitialStock(NewHarvestLot $lot): HarvestLot
    {
        return $this->created = new HarvestLot(
            2,
            $lot->lotNumber,
            $lot->productId,
            $lot->harvestYear(),
            $lot->harvestedAt,
            $lot->jarredAt,
            $lot->apiaryOrigin,
            $lot->variety,
            $lot->quantityJarred,
            $lot->quantityJarred,
            $lot->stockAlreadyRecorded,
            $lot->createdBy,
            $lot->createdAt,
        );
    }

    public function findLot(int $id): ?HarvestLot
    {
        return $this->created?->id === $id ? $this->created : null;
    }

    public function lots(): array
    {
        return [new HarvestLot(
            1,
            'L2025-EXISTANT',
            42,
            2025,
            new DateTimeImmutable('2025-06-15'),
            new DateTimeImmutable('2025-06-20 10:00:00'),
            'Luzillé',
            'Printemps',
            $this->trackedStock,
            $this->trackedStock,
            true,
            1,
            new DateTimeImmutable('2025-06-20 10:00:00'),
        )];
    }

    public function availableLotsForProduct(int $productId): array
    {
        return [];
    }

    public function addMovement(NewStockMovement $movement): StockMovement
    {
        throw new \LogicException('Not used.');
    }

    public function hasMovementReference(string $referenceKey): bool
    {
        return false;
    }

    public function orderMovements(int $orderId, int $orderItemId, StockMovementType $type): array
    {
        return [];
    }

    public function orderItemMovements(int $orderId, int $orderItemId): array
    {
        return [];
    }

    public function recentMovements(int $limit = 100): array
    {
        return [];
    }
}
