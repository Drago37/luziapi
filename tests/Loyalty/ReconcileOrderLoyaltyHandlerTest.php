<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyCommand;
use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use LuziApi\Loyalty\Application\Port\IdGenerator;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class ReconcileOrderLoyaltyHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private ReconcileOrderLoyaltyHandler $handler;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->handler = new ReconcileOrderLoyaltyHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00'), new CountingIdGenerator());
    }

    private function reconcile(int $pots, int $rewards): void
    {
        $this->handler->handle(new ReconcileOrderLoyaltyCommand(42, 'key', $pots, $rewards, 0));
    }

    public function testCreditsThenAdjustsForPartialRefundThenReversesOnCancel(): void
    {
        // Terminée : 5 pots.
        $this->reconcile(5, 0);
        self::assertSame(5, $this->ledger->orderTotals(42)['pots']);

        // Remboursement partiel : cible 3 → écart -2.
        $this->reconcile(3, 0);
        self::assertSame(3, $this->ledger->orderTotals(42)['pots']);

        // Annulation : cible 0 → écart -3.
        $this->reconcile(0, 0);
        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);

        // Re-complétion : cible 5 → écart +5.
        $this->reconcile(5, 0);
        self::assertSame(5, $this->ledger->orderTotals(42)['pots']);
    }

    public function testIsConvergentAndWritesNothingWhenAlreadyAtTarget(): void
    {
        $this->reconcile(5, 0);
        $countAfterFirst = count($this->ledger->entries);

        $this->reconcile(5, 0); // même cible : rien à écrire
        self::assertCount($countAfterFirst, $this->ledger->entries);
    }

    public function testReconcilesRewardConsumptionAndRestoration(): void
    {
        $this->reconcile(15, 1); // 15 pots achetés + 1 avantage utilisé
        $view = (new GetCustomerLoyaltyHandler($this->ledger))->handle(new GetCustomerLoyaltyQuery(['key']));
        self::assertSame(15, $view->netPots);
        self::assertSame(1, $view->rewardsAcquired);
        self::assertSame(0, $view->rewardsAvailable); // 1 acquis - 1 consommé

        // Annulation : plus de pots ni d'avantage consommé.
        $this->reconcile(0, 0);
        self::assertSame(['pots' => 0, 'rights' => 0], $this->ledger->orderTotals(42));
    }

    public function testDoesNothingWithoutCustomerKey(): void
    {
        $this->handler->handle(new ReconcileOrderLoyaltyCommand(42, '', 5, 0, 0));
        self::assertCount(0, $this->ledger->entries);
    }
}

final class CountingIdGenerator implements IdGenerator
{
    private int $n = 0;

    public function newId(): string
    {
        return 'r-' . ++$this->n;
    }
}
