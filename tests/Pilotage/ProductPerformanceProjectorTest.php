<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Product\ProductPerformanceProjector;
use LuziApi\Pilotage\Domain\Product\ProductStockSnapshot;
use LuziApi\Pilotage\Domain\Sales\OrderLineSnapshot;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class ProductPerformanceProjectorTest extends TestCase
{
    public function testItAggregatesValidatedOrderLinesAndDetectsLowStock(): void
    {
        $products = [new ProductStockSnapshot(10, 'Acacia', 'ACA', 4, 5, true, new Money(1_400))];
        $orders = [
            $this->order(1, 'completed', [new OrderLineSnapshot(10, 'Acacia', 3, new Money(4_200))]),
            $this->order(2, 'cancelled', [new OrderLineSnapshot(10, 'Acacia', 8, new Money(11_200))]),
        ];

        $result = (new ProductPerformanceProjector())->project($products, $orders);

        self::assertSame(3, $result[0]->soldQuantity);
        self::assertSame(4_200, $result[0]->revenue->cents());
        self::assertTrue($result[0]->lowStock);
    }

    /** @param list<OrderLineSnapshot> $lines */
    private function order(int $id, string $status, array $lines): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-01-01'),
            $status,
            Money::zero(),
            Money::zero(),
            0,
            'Client',
            '',
            '',
            '',
            'online',
            'pickup',
            $lines,
        );
    }
}
