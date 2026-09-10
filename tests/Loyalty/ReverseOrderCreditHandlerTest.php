<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditCommand;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditHandler;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class ReverseOrderCreditHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private RecordCompletedOrderHandler $record;
    private ReverseOrderCreditHandler $reverse;

    protected function setUp(): void
    {
        $clock = FixedClock::at('2026-09-10 10:00:00');
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->record = new RecordCompletedOrderHandler($this->ledger, $clock);
        $this->reverse = new ReverseOrderCreditHandler($this->ledger, $clock);
    }

    public function testReversalCancelsExactlyTheOriginalCredit(): void
    {
        $creditId = $this->record->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 3));

        $reversalId = $this->reverse->handle(new ReverseOrderCreditCommand(1042));

        self::assertNotNull($reversalId);
        self::assertCount(2, $this->ledger->entries);
        $reversal = $this->ledger->entries[1];
        self::assertSame(LoyaltyEntryType::PurchaseReversed, $reversal->type);
        self::assertSame(-3, $reversal->potsDelta);
        self::assertSame($creditId, $reversal->reversalOfId);
        self::assertSame('reverse:1042', $reversal->idempotencyKey);

        // Le total net revient à zéro.
        self::assertSame(0, $this->ledger->totalsForCustomerKeys(['customer-key-1'])['pots']);
    }

    public function testDoesNothingWhenNoCreditExists(): void
    {
        self::assertNull($this->reverse->handle(new ReverseOrderCreditCommand(9999)));
        self::assertCount(0, $this->ledger->entries);
    }

    public function testReversalIsIdempotent(): void
    {
        $this->record->handle(new RecordCompletedOrderCommand(1042, 'customer-key-1', 3));

        $first = $this->reverse->handle(new ReverseOrderCreditCommand(1042));
        $second = $this->reverse->handle(new ReverseOrderCreditCommand(1042));

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertCount(2, $this->ledger->entries);
    }
}
