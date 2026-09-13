<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Receipt\OrphanReceipt;
use LuziApi\Pilotage\Domain\Receipt\ReceiptDriftAuditor;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliation;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class ReceiptDriftAuditorTest extends TestCase
{
    public function testItReportsNoDriftWhenEverythingMatches(): void
    {
        $report = (new ReceiptDriftAuditor())->audit([], []);

        self::assertFalse($report->hasDrift());
        self::assertSame(0, $report->anomalyCount());
        self::assertSame(0, $report->totalDriftCents());
    }

    public function testItSplitsMissingReceiptsFromDivergentAmounts(): void
    {
        $missing = $this->reconciliation(1, expected: 3_000, recorded: 0);
        $divergent = $this->reconciliation(2, expected: 2_000, recorded: 1_200);

        $report = (new ReceiptDriftAuditor())->audit([$missing, $divergent], []);

        self::assertCount(1, $report->missingReceipts);
        self::assertSame(1, $report->missingReceipts[0]->order->id);
        self::assertCount(1, $report->divergentReceipts);
        self::assertSame(2, $report->divergentReceipts[0]->order->id);
    }

    public function testItKeepsOrphanReceiptsAndSumsTheAbsoluteDrift(): void
    {
        $missing = $this->reconciliation(1, expected: 3_000, recorded: 0);        // écart 3000
        $divergent = $this->reconciliation(2, expected: 2_000, recorded: 2_500);  // écart -500
        $orphan = new OrphanReceipt(99, new Money(1_320));                        // 1320

        $report = (new ReceiptDriftAuditor())->audit([$missing, $divergent], [$orphan]);

        self::assertTrue($report->hasDrift());
        self::assertSame(3, $report->anomalyCount());
        self::assertSame(99, $report->orphanReceipts[0]->orderId);
        self::assertSame(3_000 + 500 + 1_320, $report->totalDriftCents());
    }

    private function reconciliation(int $id, int $expected, int $recorded): ReceiptReconciliation
    {
        $order = new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-09-12'),
            'completed',
            new Money($expected),
            new Money(0),
            1,
            'Client Test',
            'client@example.test',
            '',
            'Luzillé',
            'online',
            'pickup',
        );

        return new ReceiptReconciliation(
            $order,
            new Money($expected),
            new Money($recorded),
            new Money($expected - $recorded),
        );
    }
}
