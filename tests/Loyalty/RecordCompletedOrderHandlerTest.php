<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class RecordCompletedOrderHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private RecordCompletedOrderHandler $handler;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->handler = new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00'));
    }

    public function testCreditsEligiblePotsForACompletedOrder(): void
    {
        $id = $this->handler->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 3, 7));

        self::assertNotNull($id);
        self::assertCount(1, $this->ledger->entries);
        $entry = $this->ledger->entries[0];
        self::assertSame(LoyaltyEntryType::PurchaseCredited, $entry->type);
        self::assertSame('customer-key-1', $entry->customerKey);
        self::assertSame(3, $entry->potsDelta);
        self::assertSame(0, $entry->rightsDelta);
        self::assertSame(1042, $entry->sourceOrderId);
        self::assertSame('credit:1042', $entry->idempotencyKey);
        self::assertSame(7, $entry->createdBy);
    }

    public function testIsIdempotentOnTheOrder(): void
    {
        $first = $this->handler->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 3));
        $second = $this->handler->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 3));

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertCount(1, $this->ledger->entries);
    }

    public function testDoesNothingWithoutPots(): void
    {
        self::assertNull($this->handler->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 0)));
        self::assertCount(0, $this->ledger->entries);
    }

    public function testDoesNothingWithoutCustomerKey(): void
    {
        self::assertNull($this->handler->handle(new RecordCompletedOrderCommand(1042, '', 3)));
        self::assertCount(0, $this->ledger->entries);
    }
}
