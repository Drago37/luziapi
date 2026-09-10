<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionCommand;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionCommand;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class RewardConsumptionTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private RecordCompletedOrderHandler $credit;
    private RecordRewardConsumptionHandler $consume;
    private ReverseRewardConsumptionHandler $restore;
    private GetCustomerLoyaltyHandler $query;

    protected function setUp(): void
    {
        $clock = FixedClock::at('2026-09-10 10:00:00');
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->credit = new RecordCompletedOrderHandler($this->ledger, $clock);
        $this->consume = new RecordRewardConsumptionHandler($this->ledger, $clock);
        $this->restore = new ReverseRewardConsumptionHandler($this->ledger, $clock);
        $this->query = new GetCustomerLoyaltyHandler($this->ledger);
    }

    public function testConsumingARewardLowersAvailabilityNotAcquisition(): void
    {
        $this->credit->handle(new RecordCompletedOrderCommand(1, 'key', 20)); // 2 avantages acquis

        $id = $this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 1));

        self::assertNotNull($id);
        $entry = $this->ledger->entries[1];
        self::assertSame(LoyaltyEntryType::RewardConsumed, $entry->type);
        self::assertSame(-1, $entry->rightsDelta);
        self::assertSame(0, $entry->potsDelta);

        $view = $this->query->handle(new \LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery(['key']));
        self::assertSame(2, $view->rewardsAcquired);
        self::assertSame(1, $view->rewardsAvailable);
    }

    public function testConsumptionIsIdempotent(): void
    {
        $this->credit->handle(new RecordCompletedOrderCommand(1, 'key', 20));

        $first = $this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 1));
        $second = $this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 1));

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testDoesNothingWithoutRewards(): void
    {
        self::assertNull($this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 0)));
        self::assertCount(0, $this->ledger->entries);
    }

    public function testRestoringGivesTheRewardBack(): void
    {
        $this->credit->handle(new RecordCompletedOrderCommand(1, 'key', 20));
        $this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 1));

        $restoreId = $this->restore->handle(new ReverseRewardConsumptionCommand(2));

        self::assertNotNull($restoreId);
        $restored = $this->ledger->entries[2];
        self::assertSame(LoyaltyEntryType::RewardRestored, $restored->type);
        self::assertSame(1, $restored->rightsDelta);
        // L'avantage est de nouveau disponible.
        self::assertSame(2, $this->query->availableRewards(['key']));
    }

    public function testRestoreIsIdempotentAndNoopWithoutConsumption(): void
    {
        self::assertNull($this->restore->handle(new ReverseRewardConsumptionCommand(999)));

        $this->credit->handle(new RecordCompletedOrderCommand(1, 'key', 20));
        $this->consume->handle(new RecordRewardConsumptionCommand(2, 'key', 1));
        $first = $this->restore->handle(new ReverseRewardConsumptionCommand(2));
        $second = $this->restore->handle(new ReverseRewardConsumptionCommand(2));

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testAvailableRewardsByCustomerAggregatesKeys(): void
    {
        $this->credit->handle(new RecordCompletedOrderCommand(1, 'marie-email', 6));
        $this->credit->handle(new RecordCompletedOrderCommand(2, 'marie-phone', 5)); // 11 pots -> 1 avantage
        $this->consume->handle(new RecordRewardConsumptionCommand(3, 'marie-email', 1)); // consommé

        $available = $this->query->availableRewardsByCustomer([
            'marie' => ['marie-email', 'marie-phone'],
            'autre' => ['autre-key'],
        ]);

        self::assertSame(0, $available['marie']); // 1 acquis - 1 consommé
        self::assertSame(0, $available['autre']);
    }
}
