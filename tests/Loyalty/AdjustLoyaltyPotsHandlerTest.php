<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots\AdjustLoyaltyPotsCommand;
use LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots\AdjustLoyaltyPotsHandler;
use LuziApi\Loyalty\Application\Port\IdGenerator;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class AdjustLoyaltyPotsHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private AdjustLoyaltyPotsHandler $handler;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->handler = new AdjustLoyaltyPotsHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00'), new SequentialIdGenerator());
    }

    public function testAddsAndRemovesPots(): void
    {
        $this->handler->handle(new AdjustLoyaltyPotsCommand('key', 3, 'Geste commercial', 7));
        $this->handler->handle(new AdjustLoyaltyPotsCommand('key', -2, '', 7));

        self::assertCount(2, $this->ledger->entries);
        self::assertSame(LoyaltyEntryType::ManualAdjustment, $this->ledger->entries[0]->type);
        self::assertSame(3, $this->ledger->entries[0]->potsDelta);
        self::assertSame('Geste commercial', $this->ledger->entries[0]->reason);
        self::assertSame(-2, $this->ledger->entries[1]->potsDelta);
        self::assertSame('Ajustement manuel', $this->ledger->entries[1]->reason);

        $view = (new GetCustomerLoyaltyHandler($this->ledger))->handle(new GetCustomerLoyaltyQuery(['key']));
        self::assertSame(1, $view->netPots);
    }

    public function testIgnoresZeroDeltaOrMissingCustomer(): void
    {
        self::assertNull($this->handler->handle(new AdjustLoyaltyPotsCommand('key', 0, 'x', 7)));
        self::assertNull($this->handler->handle(new AdjustLoyaltyPotsCommand('', 3, 'x', 7)));
        self::assertCount(0, $this->ledger->entries);
    }
}

final class SequentialIdGenerator implements IdGenerator
{
    private int $n = 0;

    public function newId(): string
    {
        return 'id-' . ++$this->n;
    }
}
