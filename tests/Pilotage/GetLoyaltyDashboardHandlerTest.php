<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class GetLoyaltyDashboardHandlerTest extends TestCase
{
    private function handler(): GetLoyaltyDashboardHandler
    {
        return new GetLoyaltyDashboardHandler(
            new OrderRepositoryStub([
                // Alice : commande 2026 (5 pots, 1 offert, 2,50 €) et 2025 (4 pots).
                $this->order(1, 'Alice', 'alice@example.test', '2026-05-10'),
                $this->order(3, 'Alice', 'alice@example.test', '2025-03-01'),
                // Bob : commande 2026 (3 pots).
                $this->order(2, 'Bob', 'bob@example.test', '2026-06-15'),
                // Commande sans contact : ignorée par le projecteur.
                $this->order(4, '', '', '2026-07-01'),
            ]),
            new CustomerHistoryProjector(),
            new LoyaltyEconomicsReaderStub([
                1 => ['potsBought' => 5, 'offeredPots' => 1, 'discountCents' => 250],
                2 => ['potsBought' => 3, 'offeredPots' => 0, 'discountCents' => 0],
                3 => ['potsBought' => 4, 'offeredPots' => 0, 'discountCents' => 0],
            ]),
            new DashboardClock(),
        );
    }

    public function testAggregatesAndRanksForTheSelectedYear(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery(2026));

        self::assertSame(2026, $view->year);
        self::assertSame([2026, 2025], $view->availableYears);
        self::assertSame(2, $view->totalCustomers);
        self::assertSame(8, $view->totalPots); // 5 (Alice 2026) + 3 (Bob) — la commande 2025 exclue
        self::assertSame(1, $view->totalOfferedPots);
        self::assertSame(250, $view->totalDiscountCents);

        self::assertSame('Alice', $view->customers[0]->name);
        self::assertSame(5, $view->customers[0]->potsBought);
        self::assertSame('Bob', $view->customers[1]->name);

        self::assertSame('Alice', $view->topBuyers[0]->name);
        self::assertSame('Alice', $view->topBenefited[0]->name);
        self::assertSame('Alice', $view->topDiscounts[0]->name);
        self::assertCount(1, $view->topBenefited);
        self::assertCount(1, $view->topDiscounts);
    }

    public function testYearFilteringIsolatesEachYear(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery(2025));

        self::assertSame(2025, $view->year);
        self::assertSame(1, $view->totalCustomers); // seule Alice a une commande 2025
        self::assertSame(4, $view->totalPots);
        self::assertSame('Alice', $view->customers[0]->name);
    }

    public function testUnknownYearFallsBackToMostRecentAvailable(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery(1999));

        self::assertSame(2026, $view->year);
    }

    private function order(int $id, string $name, string $email, string $date): OrderSnapshot
    {
        $when = new DateTimeImmutable($date . ' 10:00:00', new DateTimeZone('Europe/Paris'));

        return new OrderSnapshot(
            $id,
            (string) $id,
            $when,
            'completed',
            new Money(3_600),
            Money::zero(),
            3,
            $name,
            $email,
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

final class OrderRepositoryStub implements OrderRepository
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
}

final class LoyaltyEconomicsReaderStub implements LoyaltyEconomicsReader
{
    /** @param array<int, array{potsBought:int, offeredPots:int, discountCents:int}> $byOrderId */
    public function __construct(private array $byOrderId)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        $potsBought = 0;
        $offeredPots = 0;
        $discountCents = 0;
        foreach ($orderIds as $orderId) {
            $potsBought += $this->byOrderId[$orderId]['potsBought'] ?? 0;
            $offeredPots += $this->byOrderId[$orderId]['offeredPots'] ?? 0;
            $discountCents += $this->byOrderId[$orderId]['discountCents'] ?? 0;
        }

        return ['potsBought' => $potsBought, 'offeredPots' => $offeredPots, 'discountCents' => $discountCents];
    }
}

final class DashboardClock implements Clock
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
