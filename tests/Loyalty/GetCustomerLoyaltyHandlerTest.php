<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class GetCustomerLoyaltyHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private RecordCompletedOrderHandler $record;
    private GetCustomerLoyaltyHandler $query;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->record = new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00'));
        $this->query = new GetCustomerLoyaltyHandler($this->ledger);
    }

    public function testEmptyWhenNoKeys(): void
    {
        $view = $this->query->handle(new GetCustomerLoyaltyQuery([]));

        self::assertSame(0, $view->netPots);
        self::assertSame(0, $view->rewardsAcquired);
        self::assertSame([], $view->entries);
    }

    public function testAggregatesAcrossAllIdentityKeysOfTheProfile(): void
    {
        // Même client, deux commandes rattachées à deux clés d'identité distinctes
        // (ex. une par e-mail, une par téléphone) — la fiche agrège les deux.
        $this->record->handle(new RecordCompletedOrderCommand(1, 'key-email', 10));
        $this->record->handle(new RecordCompletedOrderCommand(2, 'key-phone', 6));

        $view = $this->query->handle(new GetCustomerLoyaltyQuery(['key-email', 'key-phone']));

        self::assertSame(16, $view->netPots);
        self::assertSame(1, $view->rewardsAcquired);
        self::assertSame(1, $view->potsTowardNextReward);
        self::assertCount(2, $view->entries);
    }

    public function testIgnoresOtherCustomersEntries(): void
    {
        $this->record->handle(new RecordCompletedOrderCommand(1, 'key-mine', 4));
        $this->record->handle(new RecordCompletedOrderCommand(2, 'key-other', 9));

        $view = $this->query->handle(new GetCustomerLoyaltyQuery(['key-mine']));

        self::assertSame(4, $view->netPots);
        self::assertCount(1, $view->entries);
    }
}
