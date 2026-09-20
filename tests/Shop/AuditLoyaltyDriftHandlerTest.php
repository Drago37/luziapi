<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Application\Port\EligiblePotReader;
use LuziApi\Shop\Application\Port\LoyaltyLedgerReader;
use LuziApi\Shop\Application\Query\AuditLoyaltyDrift\AuditLoyaltyDriftHandler;
use LuziApi\Shop\Application\Query\AuditLoyaltyDrift\AuditLoyaltyDriftQuery;
use LuziApi\Shop\Domain\Sales\OrderRepository;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class AuditLoyaltyDriftHandlerTest extends TestCase
{
    public function testFlagsUncreditedEligibleOrdersAndOrphanCredits(): void
    {
        $handler = new AuditLoyaltyDriftHandler(
            new AuditOrderRepositoryStub([
                $this->order(1, 'completed'), // admissible + déjà créditée → sain
                $this->order(2, 'completed'), // admissible + jamais créditée → trou
                $this->order(3, 'completed'), // aucun pot admissible → ignorée
                $this->order(4, 'pending'),   // pas terminée → ignorée
            ]),
            new EligiblePotReaderStub([1 => 5, 2 => 3, 3 => 0, 4 => 4]),
            // Le journal cite les commandes 1 (existante) et 99 (disparue, 6 pots nets).
            new AuditLedgerReaderStub([1, 99], [99 => 6]),
            new AuditClock(),
        );

        $report = $handler->handle(new AuditLoyaltyDriftQuery());

        self::assertCount(1, $report->creditGaps);
        self::assertSame(2, $report->creditGaps[0]->orderId);
        self::assertSame(3, $report->creditGaps[0]->eligiblePots);

        self::assertCount(1, $report->orphanCredits);
        self::assertSame(99, $report->orphanCredits[0]->orderId);
        self::assertSame(6, $report->orphanCredits[0]->netPots);

        self::assertTrue($report->hasDrift());
        self::assertSame(2, $report->anomalyCount());
        self::assertSame(3, $report->totalMissingPots());
        self::assertSame(6, $report->totalOrphanPots());
    }

    public function testNoDriftWhenEverythingIsCreditedAndNoOrphan(): void
    {
        $handler = new AuditLoyaltyDriftHandler(
            new AuditOrderRepositoryStub([$this->order(1, 'completed')]),
            new EligiblePotReaderStub([1 => 5]),
            new AuditLedgerReaderStub([1], []),
            new AuditClock(),
        );

        $report = $handler->handle(new AuditLoyaltyDriftQuery());

        self::assertFalse($report->hasDrift());
        self::assertSame(0, $report->anomalyCount());
    }

    private function order(int $id, string $status): OrderSnapshot
    {
        $when = new DateTimeImmutable('2026-05-10 10:00:00', new DateTimeZone('Europe/Paris'));

        return new OrderSnapshot(
            $id,
            (string) $id,
            $when,
            $status,
            new Money(3_600),
            Money::zero(),
            3,
            'Client',
            'client@example.test',
            '',
            'Luzillé',
            'market',
            'immediate',
            [],
            '',
            $when,
        );
    }
}

final class AuditOrderRepositoryStub implements OrderRepository
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

final class EligiblePotReaderStub implements EligiblePotReader
{
    /** @param array<int, int> $byOrderId */
    public function __construct(private array $byOrderId)
    {
    }

    public function eligiblePotsByOrderIds(array $orderIds): array
    {
        $out = [];
        foreach ($orderIds as $orderId) {
            $out[$orderId] = $this->byOrderId[$orderId] ?? 0;
        }

        return $out;
    }
}

final class AuditLedgerReaderStub implements LoyaltyLedgerReader
{
    /**
     * @param list<int>       $sourceOrderIds
     * @param array<int, int> $netByOrderId
     */
    public function __construct(
        private array $sourceOrderIds,
        private array $netByOrderId,
    ) {
    }

    public function sourceOrderIds(): array
    {
        return $this->sourceOrderIds;
    }

    public function netPotsByOrderIds(array $orderIds): array
    {
        $out = [];
        foreach ($orderIds as $orderId) {
            $out[$orderId] = $this->netByOrderId[$orderId] ?? 0;
        }

        return $out;
    }
}

final class AuditClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-10 10:00:00', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}
