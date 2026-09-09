<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class ReceiptReconciliationProjectorTest extends TestCase
{
    public function testItOnlyReturnsCommercialOrdersWhoseRecordedAmountDiffers(): void
    {
        $orders = [
            $this->order(1, 'completed', 3_000, 500),
            $this->order(2, 'processing', 2_000, 0),
            $this->order(3, 'cancelled', 4_000, 0),
        ];

        $items = (new ReceiptReconciliationProjector())->project($orders, [1 => 2_500, 2 => 1_200]);

        self::assertCount(1, $items);
        self::assertSame(2, $items[0]->order->id);
        self::assertSame(800, $items[0]->difference->cents());
    }

    public function testItHighlightsAnOverRecordedOrder(): void
    {
        $items = (new ReceiptReconciliationProjector())->project(
            [$this->order(1, 'completed', 2_000, 0)],
            [1 => 2_500],
        );

        self::assertSame(-500, $items[0]->difference->cents());
    }

    private function order(int $id, string $status, int $total, int $refunded): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-09-09'),
            $status,
            new Money($total),
            new Money($refunded),
            1,
            'Client Test',
            'client@example.test',
            '',
            'Luzillé',
            'online',
            'pickup',
        );
    }
}
