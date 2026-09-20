<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Application\Query\AuditReceiptDrift\AuditReceiptDriftHandler;
use LuziApi\Shop\Application\Query\AuditReceiptDrift\AuditReceiptDriftQuery;
use LuziApi\Shop\Domain\Receipt\NewReceiptEntry;
use LuziApi\Shop\Domain\Receipt\ReceiptDriftAuditor;
use LuziApi\Shop\Domain\Receipt\ReceiptEntry;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use LuziApi\Shop\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Shop\Domain\Receipt\ReceiptRepository;
use LuziApi\Shop\Domain\Sales\OrderRepository;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shop\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class AuditReceiptDriftHandlerTest extends TestCase
{
    public function testItDetectsMissingDivergentAndOrphanReceipts(): void
    {
        $orders = [
            $this->order(1, 3_000), // recette 3000 → concorde, ignorée
            $this->order(2, 2_000), // recette 1200 → montant divergent (800)
            $this->order(3, 1_500), // aucune recette → manquante
        ];
        // La recette 99 pointe une commande disparue (absente de existingOrderIds).
        $netByOrder = [1 => 3_000, 2 => 1_200, 99 => 1_320];
        $receiptsInPeriod = [
            $this->entry(1),
            $this->entry(2),
            $this->entry(99),
        ];

        $handler = new AuditReceiptDriftHandler(
            new InMemoryDriftReceiptRepository($netByOrder, $receiptsInPeriod),
            new InMemoryDriftOrderRepository($orders),
            new ReceiptReconciliationProjector(),
            new ReceiptDriftAuditor(),
            new FixedDriftClock(),
        );

        $report = $handler->handle(new AuditReceiptDriftQuery(2026));

        self::assertCount(1, $report->missingReceipts);
        self::assertSame(3, $report->missingReceipts[0]->order->id);

        self::assertCount(1, $report->divergentReceipts);
        self::assertSame(2, $report->divergentReceipts[0]->order->id);
        self::assertSame(800, $report->divergentReceipts[0]->difference->cents());

        self::assertCount(1, $report->orphanReceipts);
        self::assertSame(99, $report->orphanReceipts[0]->orderId);
        self::assertSame(1_320, $report->orphanReceipts[0]->net->cents());

        self::assertSame(1_500 + 800 + 1_320, $report->totalDriftCents());
    }

    public function testItReportsNoDriftWhenReceiptsMatchOrders(): void
    {
        $handler = new AuditReceiptDriftHandler(
            new InMemoryDriftReceiptRepository([1 => 3_000], [$this->entry(1)]),
            new InMemoryDriftOrderRepository([$this->order(1, 3_000)]),
            new ReceiptReconciliationProjector(),
            new ReceiptDriftAuditor(),
            new FixedDriftClock(),
        );

        self::assertFalse($handler->handle(new AuditReceiptDriftQuery(2026))->hasDrift());
    }

    private function order(int $id, int $total): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-06-01'),
            'completed',
            new Money($total),
            new Money(0),
            1,
            'Client Test',
            'client@example.test',
            '',
            'Luzillé',
            'online',
            'pickup',
        );
    }

    private function entry(int $orderId): ReceiptEntry
    {
        return new ReceiptEntry(
            $orderId,
            $orderId,
            $orderId,
            new DateTimeImmutable('2026-06-01'),
            new Money(0),
            'cash',
            ReceiptEntryType::Collection,
            '',
            null,
            0,
            new DateTimeImmutable('2026-06-01'),
        );
    }
}

final class InMemoryDriftReceiptRepository implements ReceiptRepository
{
    /**
     * @param array<int, int>    $netByOrder
     * @param list<ReceiptEntry> $inPeriod
     */
    public function __construct(
        private array $netByOrder,
        private array $inPeriod,
    ) {
    }

    public function add(NewReceiptEntry $entry): ReceiptEntry
    {
        throw new \LogicException('non utilisé');
    }

    public function find(int $id): ?ReceiptEntry
    {
        return null;
    }

    public function hasReversalFor(int $entryId): bool
    {
        return false;
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->inPeriod;
    }

    public function netTotalsByOrderIds(array $orderIds): array
    {
        $totals = [];
        foreach ($orderIds as $orderId) {
            if (isset($this->netByOrder[$orderId])) {
                $totals[$orderId] = $this->netByOrder[$orderId];
            }
        }

        return $totals;
    }

    public function deleteByOrderId(int $orderId): int
    {
        return 0;
    }
}

final class InMemoryDriftOrderRepository implements OrderRepository
{
    /** @param list<OrderSnapshot> $orders */
    public function __construct(private array $orders)
    {
    }

    public function createdBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->orders;
    }

    public function firstOrderDate(): ?DateTimeImmutable
    {
        return $this->orders[0]->createdAt ?? null;
    }

    public function existingOrderIds(array $ids): array
    {
        $known = array_map(static fn (OrderSnapshot $order): int => $order->id, $this->orders);

        return array_values(array_intersect($ids, $known));
    }
}

final class FixedDriftClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 12:00:00');
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}
