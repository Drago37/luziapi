<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Port\OrderContactKeys;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetLoyaltyForOrders\GetLoyaltyForOrdersHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class GetLoyaltyForOrdersHandlerTest extends TestCase
{
    public function testEmptyViewWhenOrdersHaveNoContactKeys(): void
    {
        $ledger = new InMemoryLoyaltyLedger();
        $handler = new GetLoyaltyForOrdersHandler(
            new FakeOrderContactKeys([]),
            new GetCustomerLoyaltyHandler($ledger),
        );

        $view = $handler->handle([1, 2]);

        self::assertSame(0, $view->netPots);
        self::assertSame([], $view->entries);
    }

    public function testAggregatesLoyaltyForTheOrdersCustomer(): void
    {
        $ledger = new InMemoryLoyaltyLedger();
        (new RecordCompletedOrderHandler($ledger, FixedClock::at('2026-09-10 10:00:00')))
            ->handle(new RecordCompletedOrderCommand(1, 'client-key', 16));

        $handler = new GetLoyaltyForOrdersHandler(
            new FakeOrderContactKeys(['client-key']),
            new GetCustomerLoyaltyHandler($ledger),
        );

        $view = $handler->handle([1]);

        self::assertSame(16, $view->netPots);
        self::assertSame(1, $view->rewardsAvailable);
        self::assertSame(1, $view->potsTowardNextReward);
        self::assertSame(14, $view->potsUntilNextReward);
    }
}

final class FakeOrderContactKeys implements OrderContactKeys
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
    }

    public function forOrderIds(array $orderIds): array
    {
        return $this->keys;
    }
}
