<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Sales\AnnualSalesCalculator;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class AnnualSalesCalculatorTest extends TestCase
{
    public function testItSeparatesValidatedOrdersFromCancelledAndPendingOrders(): void
    {
        $orders = [
            $this->order(1, 'processing', 2_400, 0, 2, '2026-01-15', 'phone'),
            $this->order(2, 'completed', 1_200, 200, 1, '2026-02-10', 'online'),
            $this->order(3, 'cancelled', 4_000, 0, 3, '2026-02-12', 'online'),
            $this->order(4, 'pending', 1_000, 0, 1, '2026-03-05', ''),
        ];

        $summary = (new AnnualSalesCalculator())->calculate(2026, $orders);

        self::assertSame(3_600, $summary->orderedTotal->cents());
        self::assertSame(200, $summary->refundedTotal->cents());
        self::assertSame(3_400, $summary->netOrderedTotal->cents());
        self::assertSame(2, $summary->ordersCount);
        self::assertSame(3, $summary->itemsCount);
        self::assertSame(1_800, $summary->averageOrder->cents());
        self::assertSame(2_400, $summary->monthlyTotalsCents[1]);
        self::assertSame(1_200, $summary->monthlyTotalsCents[2]);
        self::assertSame(1, $summary->statusCounts['cancelled']);
        self::assertSame(2_400, $summary->sourceTotalsCents['phone']);
        self::assertSame(1_200, $summary->sourceTotalsCents['online']);
        self::assertSame('2', $summary->recentOrders[0]->number);
    }

    private function order(
        int $id,
        string $status,
        int $total,
        int $refunded,
        int $items,
        string $date,
        string $source,
    ): OrderSnapshot {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable($date),
            $status,
            new Money($total),
            new Money($refunded),
            $items,
            'Client test',
            'client@example.test',
            '06 00 00 00 00',
            'Luzillé',
            $source,
            'pickup',
        );
    }
}
