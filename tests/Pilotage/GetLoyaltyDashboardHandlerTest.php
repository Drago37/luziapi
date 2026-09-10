<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use LuziApi\Tests\Loyalty\FixedClock as LoyaltyFixedClock;
use LuziApi\Tests\Loyalty\InMemoryLoyaltyLedger;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Loyalty/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/../Loyalty/FixedClock.php';

final class GetLoyaltyDashboardHandlerTest extends TestCase
{
    public function testRanksCustomersAndAggregatesEconomics(): void
    {
        $ledger = new InMemoryLoyaltyLedger();
        $credit = new RecordCompletedOrderHandler($ledger, LoyaltyFixedClock::at('2026-09-01 10:00:00'));
        // Alice : 5 pots ; Bob : 3 pots (clés d'identité = celles du projecteur).
        $credit->handle(new RecordCompletedOrderCommand(1, LoyaltyIdentity::hash('email:alice@example.test'), 5));
        $credit->handle(new RecordCompletedOrderCommand(2, LoyaltyIdentity::hash('email:bob@example.test'), 3));

        $handler = new GetLoyaltyDashboardHandler(
            new OrderRepositoryStub([
                $this->order(1, 'Alice', 'alice@example.test'),
                $this->order(2, 'Bob', 'bob@example.test'),
                $this->order(3, '', ''), // commande sans contact : ignorée
            ]),
            new CustomerHistoryProjector(),
            new LoyaltyEconomicsReaderStub([1 => ['discountCents' => 250, 'offeredPots' => 1]]),
            new DashboardClock(),
            new GetCustomerLoyaltyHandler($ledger),
        );

        $view = $handler->handle(new GetLoyaltyDashboardQuery());

        self::assertSame(2, $view->totalCustomers);
        self::assertSame(8, $view->totalPots);
        self::assertSame(1, $view->totalOfferedPots);
        self::assertSame(250, $view->totalDiscountCents);

        // Récap trié par pots achetés : Alice (5) avant Bob (3).
        self::assertSame('Alice', $view->customers[0]->name);
        self::assertSame(5, $view->customers[0]->netPots);
        self::assertSame('Bob', $view->customers[1]->name);

        // Tops.
        self::assertSame('Alice', $view->topBuyers[0]->name);
        self::assertSame('Alice', $view->topBenefited[0]->name);
        self::assertSame(1, $view->topBenefited[0]->offeredPots);
        self::assertSame('Alice', $view->topDiscounts[0]->name);
        self::assertSame(250, $view->topDiscounts[0]->discountCents);
        // Bob n'a ni pot offert ni remise : absent des tops correspondants.
        self::assertCount(1, $view->topBenefited);
        self::assertCount(1, $view->topDiscounts);
    }

    public function testEmptyWithoutLoyaltyHandler(): void
    {
        $handler = new GetLoyaltyDashboardHandler(
            new OrderRepositoryStub([$this->order(1, 'Alice', 'alice@example.test')]),
            new CustomerHistoryProjector(),
            new LoyaltyEconomicsReaderStub([]),
            new DashboardClock(),
            null,
        );

        $view = $handler->handle(new GetLoyaltyDashboardQuery());

        self::assertSame(0, $view->totalCustomers);
        self::assertSame([], $view->customers);
    }

    private function order(int $id, string $name, string $email): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-09-0' . $id . ' 10:00:00', new DateTimeZone('Europe/Paris')),
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
    /** @param array<int, array{discountCents:int, offeredPots:int}> $byOrderId */
    public function __construct(private array $byOrderId)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        $discountCents = 0;
        $offeredPots = 0;
        foreach ($orderIds as $orderId) {
            $discountCents += $this->byOrderId[$orderId]['discountCents'] ?? 0;
            $offeredPots += $this->byOrderId[$orderId]['offeredPots'] ?? 0;
        }

        return ['discountCents' => $discountCents, 'offeredPots' => $offeredPots];
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
