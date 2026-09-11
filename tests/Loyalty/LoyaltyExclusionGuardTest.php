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
use RuntimeException;
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

    public function testTogglingExclusionRecalculatesPotsAndRewards(): void
    {
        // rewards:1 => on exerce aussi la restitution d'un avantage consommé.
        $subscriber = $this->subscriber(eligible: 2, rewards: 1);
        $order = $this->order('completed');

        // Crédit initial au passage « Terminée » (hook automatique) : 2 pots, 1 avantage.
        $subscriber->reconcile(42, $order);
        self::assertSame(2, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(-1, $this->ledger->orderTotals(42)['rights']);

        // On coche « exclure » : recalcul => pots retirés ET avantage restitué.
        $order->update_meta_data(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META, 'yes');
        $subscriber->onExclusionChanged(42, $order);
        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(0, $this->ledger->orderTotals(42)['rights']);

        // On décoche : recalcul => pots et avantage réattribués.
        $order->delete_meta_data(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META);
        $subscriber->onExclusionChanged(42, $order);
        self::assertSame(2, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(-1, $this->ledger->orderTotals(42)['rights']);
    }

    public function testOrderWithoutResolvableContactEarnsNothing(): void
    {
        // resolve() renvoie null (aucun e-mail/téléphone) => sortie anticipée, rien au journal.
        $this->subscriber(eligible: 2, rewards: 1, contactKey: null)->reconcile(42, $this->order('completed'));

        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);
        self::assertSame(0, $this->ledger->orderTotals(42)['rights']);
    }

    public function testReconcileNeverPropagatesACollaboratorFailure(): void
    {
        // Le workflow de commande ne doit jamais casser : reconcile() avale et journalise.
        $this->throwingSubscriber()->reconcile(42, $this->order('completed'));

        self::assertSame(0, $this->ledger->orderTotals(42)['pots']);
    }

    public function testOnExclusionChangedRecordsAnAdminNoticeWhenRecalcFails(): void
    {
        // Un opérateur est présent : l'échec est journalisé ET signalé par un transient.
        $key = 'luziapi_loyalty_reconcile_failed_' . get_current_user_id();
        delete_transient($key);

        $this->throwingSubscriber()->onExclusionChanged(42, $this->order('completed'));

        self::assertSame(42, (int) get_transient($key));
    }

    public function testRenderReconcileFailureNoticeShowsOnceThenClears(): void
    {
        $key = 'luziapi_loyalty_reconcile_failed_' . get_current_user_id();
        $subscriber = $this->subscriber(eligible: 0, rewards: 0);

        // Sans avis déposé : rien ne s'affiche.
        delete_transient($key);
        self::assertSame('', $this->capture($subscriber));

        // Un avis déposé s'affiche une fois (avec le numéro de commande)…
        set_transient($key, 42, 120);
        $first = $this->capture($subscriber);
        self::assertStringContainsString('#42', $first);
        self::assertStringContainsString('notice-error', $first);

        // …puis est purgé (pas de bannière collante aux chargements suivants).
        self::assertSame('', $this->capture($subscriber));
    }

    private function capture(WooCommerceLoyaltyEarningSubscriber $subscriber): string
    {
        ob_start();
        $subscriber->renderReconcileFailureNotice();

        return (string) ob_get_clean();
    }

    private function order(string $status): WC_Order
    {
        return new WC_Order('', [], '', $status);
    }

    private function throwingSubscriber(): WooCommerceLoyaltyEarningSubscriber
    {
        $resolver = new class implements OrderIdentityResolver {
            public function resolve(WC_Order $order): ?string
            {
                throw new RuntimeException('resolver down');
            }
        };

        return $this->makeSubscriber($this->counter(0, 0), $resolver);
    }

    private function counter(int $eligible, int $rewards): EligiblePotCounter
    {
        return new class($eligible, $rewards) implements EligiblePotCounter {
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
    }

    private function subscriber(int $eligible, int $rewards, ?string $contactKey = 'contact-key'): WooCommerceLoyaltyEarningSubscriber
    {
        $resolver = new class($contactKey) implements OrderIdentityResolver {
            public function __construct(private ?string $contactKey)
            {
            }

            public function resolve(WC_Order $order): ?string
            {
                return $this->contactKey;
            }
        };

        return $this->makeSubscriber($this->counter($eligible, $rewards), $resolver);
    }

    private function makeSubscriber(EligiblePotCounter $counter, OrderIdentityResolver $resolver): WooCommerceLoyaltyEarningSubscriber
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

        return new WooCommerceLoyaltyEarningSubscriber($handler, $counter, $resolver, new NullLogger());
    }
}
