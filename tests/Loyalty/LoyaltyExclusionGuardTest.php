<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use LuziApi\Loyalty\Application\Port\IdGenerator;
use LuziApi\Loyalty\Infrastructure\WooCommerce\EligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\OrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceLoyaltyEarningSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WC_Order;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

/**
 * Couvre la garde d'exclusion fidélité du subscriber : une commande portant la
 * méta `_luziapi_loyalty_excluded = yes` ne cumule jamais pots ni avantages,
 * même « Terminée » (cas de l'import d'historique).
 */
final class LoyaltyExclusionGuardTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
    }

    public function testCompletedOrderCreditsPotsAndConsumesRewards(): void
    {
        $this->subscriber(eligible: 2, rewards: 1)->reconcile(42, $this->order('completed'));

        // 2 pots crédités ; 1 avantage consommé => delta de droits négatif.
        self::assertSame(2, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(-1, $this->ledger->orderTotals(42)['rights']);
    }

    public function testExcludedOrderEarnsNothingEvenWhenCompleted(): void
    {
        $order = $this->order('completed');
        $order->update_meta_data(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META, 'yes');

        $this->subscriber(eligible: 2, rewards: 1)->reconcile(42, $order);

        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(0, $this->ledger->orderTotals(42)['rights']);
    }

    public function testNonCompletedOrderEarnsNothing(): void
    {
        $this->subscriber(eligible: 2, rewards: 1)->reconcile(42, $this->order('processing'));

        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);
    }

    public function testTogglingExclusionRecalculatesTheOrder(): void
    {
        $subscriber = $this->subscriber(eligible: 2, rewards: 0);
        $order = $this->order('completed');

        // Crédit initial (commande éditée / enregistrée).
        $subscriber->onOrderEdited(42, $order);
        self::assertSame(2, $this->ledger->orderTotals(42)['pots']);

        // On coche « exclure » : recalcul => les pots déjà crédités sont retirés.
        $order->update_meta_data(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META, 'yes');
        $subscriber->onOrderEdited(42, $order);
        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);

        // On décoche : recalcul => les pots sont réattribués.
        $order->delete_meta_data(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META);
        $subscriber->onOrderEdited(42, $order);
        self::assertSame(2, $this->ledger->orderTotals(42)['pots']);
    }

    private function order(string $status): WC_Order
    {
        return new WC_Order('', [], '', $status);
    }

    private function subscriber(int $eligible, int $rewards): WooCommerceLoyaltyEarningSubscriber
    {
        $handler = new ReconcileOrderLoyaltyHandler(
            $this->ledger,
            FixedClock::at('2026-09-11 10:00:00'),
            new class implements IdGenerator {
                private int $n = 0;

                public function newId(): string
                {
                    return 'id-' . ++$this->n;
                }
            },
        );

        $counter = new class($eligible, $rewards) implements EligiblePotCounter {
            public function __construct(private int $eligible, private int $rewards)
            {
            }

            public function countEligiblePots(WC_Order $order): int
            {
                return $this->eligible;
            }

            public function countRewardPots(WC_Order $order): int
            {
                return $this->rewards;
            }
        };

        $resolver = new class implements OrderIdentityResolver {
            public function resolve(WC_Order $order): ?string
            {
                return 'contact-key';
            }
        };

        return new WooCommerceLoyaltyEarningSubscriber($handler, $counter, $resolver, new NullLogger());
    }
}
