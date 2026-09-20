<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shop\Application\Port\Clock;
use LuziApi\Shop\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Shop\Application\Port\LoyaltyRewardsReader;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Shop\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Shop\Domain\Sales\OrderRepository;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shop\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class GetLoyaltyDashboardHandlerTest extends TestCase
{
    private function handler(?LoyaltyRewardsReader $rewards = null): GetLoyaltyDashboardHandler
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
            $rewards,
        );
    }

    public function testAggregatesAndRanksForTheSelectedYear(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('2026'));

        self::assertSame('2026', $view->periodKey);
        self::assertSame([2026, 2025], $view->availableYears);
        self::assertSame(2, $view->totalCustomers);
        self::assertSame(8, $view->totalPots); // 5 (Alice 2026) + 3 (Bob) — la commande 2025 exclue
        self::assertSame(1, $view->totalOfferedPots);
        self::assertSame(250, $view->totalDiscountCents);

        // Encart total toutes années : Alice 5+4=9 + Bob 3 = 12 pots.
        self::assertSame(2, $view->grandCustomers);
        self::assertSame(12, $view->grandPots);
        self::assertSame(1, $view->grandOfferedPots);
        self::assertSame(250, $view->grandDiscountCents);

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
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('2025'));

        self::assertSame('2025', $view->periodKey);
        self::assertSame(1, $view->totalCustomers); // seule Alice a une commande 2025
        self::assertSame(4, $view->totalPots);
        self::assertSame('Alice', $view->customers[0]->name);
        self::assertSame(12, $view->grandPots); // total toutes années inchangé
    }

    public function testDefaultPeriodCoversAllYears(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery());

        // Par défaut = toutes les années (plus de fenêtre glissante « 2 ans »).
        self::assertSame('all', $view->periodKey);
        self::assertSame(2, $view->totalCustomers);
        self::assertSame(12, $view->totalPots); // Alice 9 (2026+2025) + Bob 3
        self::assertSame('Alice', $view->topBuyers[0]->name);
        self::assertSame(9, $view->topBuyers[0]->potsBought);
    }

    public function testAllPeriodSumsEveryYear(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('all'));

        self::assertSame('all', $view->periodKey);
        self::assertSame(12, $view->totalPots);
    }

    public function testUnknownPeriodFallsBackToAllYears(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('1999'));

        self::assertSame('all', $view->periodKey);
    }

    public function testRewardsLiabilityIsZeroWithoutAReader(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('2026'));

        self::assertSame(0, $view->grandRewardsOwed);
    }

    public function testAccumulatesTheOutstandingRewardsLiability(): void
    {
        // Le lecteur d'avantages rend 1 avantage dû par client identifié ; le passif
        // est donc le nombre de clients de fidélité (Alice + Bob = 2), toutes années.
        $view = $this->handler(new LoyaltyRewardsReaderStub())->handle(new GetLoyaltyDashboardQuery('2026'));

        self::assertSame(2, $view->grandRewardsOwed);
        self::assertSame($view->grandCustomers, $view->grandRewardsOwed);
    }

    public function testListsCustomersWithOutstandingRewards(): void
    {
        // Le lecteur rend 1 avantage par client identifié : Alice et Bob figurent
        // donc dans la liste « en cours », chacun avec 1, quelle que soit la période.
        $view = $this->handler(new LoyaltyRewardsReaderStub())->handle(new GetLoyaltyDashboardQuery('2026'));

        self::assertCount(2, $view->rewardsOutstanding);
        self::assertSame(1, $view->rewardsOutstanding[0]->rewards);
        self::assertContains(
            $view->rewardsOutstanding[0]->name,
            ['Alice', 'Bob'],
        );
    }

    public function testNoOutstandingRewardsWithoutAReader(): void
    {
        $view = $this->handler()->handle(new GetLoyaltyDashboardQuery('2026'));

        self::assertSame([], $view->rewardsOutstanding);
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

    public function existingOrderIds(array $ids): array
    {
        $known = array_map(static fn (OrderSnapshot $order): int => $order->id, $this->orders);

        return array_values(array_intersect($ids, $known));
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

final class LoyaltyRewardsReaderStub implements LoyaltyRewardsReader
{
    /**
     * @param array<string, list<string>> $keysByCustomer
     *
     * @return array<string, int>
     */
    public function availableRewardsByCustomer(array $keysByCustomer): array
    {
        // Un avantage dû par client identifié (suffit à vérifier l'agrégation du passif).
        return array_map(static fn (array $keys): int => 1, $keysByCustomer);
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
