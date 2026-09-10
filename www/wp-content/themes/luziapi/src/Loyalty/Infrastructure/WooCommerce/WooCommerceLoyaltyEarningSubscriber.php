<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyCommand;
use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use Psr\Log\LoggerInterface;
use WC_Order;

/**
 * Moteur de fidélité, branché sur le cycle de vie des commandes par
 * **réconciliation** : à chaque événement (passage « Terminée », changement de
 * statut, remboursement), on porte le journal de la commande à son état cible
 * (pots éligibles + pots offerts si « Terminée », zéro sinon). Le handler n'écrit
 * que l'écart, ce qui couvre uniformément les remboursements partiels, totaux, les
 * annulations et les re-complétions. Les ventes rapides comptent comme les autres.
 * Toute panne est journalisée mais ne perturbe jamais le workflow de commande.
 */
final readonly class WooCommerceLoyaltyEarningSubscriber
{
    public function __construct(
        private ReconcileOrderLoyaltyHandler $reconcile,
        private WooCommerceEligiblePotCounter $counter,
        private WooCommerceOrderIdentityResolver $identityResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        // Priorité 5 : la réconciliation est écrite AVANT l'envoi des e-mails
        // (WooCommerce les déclenche en priorité 10), pour un compteur à jour.
        add_action('woocommerce_order_status_completed', [$this, 'onCompleted'], 5, 2);
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 30, 4);
        add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 20, 2);
    }

    /** @param mixed $order */
    public function onCompleted(int $orderId, $order = null): void
    {
        $this->reconcile($orderId, $order instanceof WC_Order ? $order : null);
    }

    /** @param mixed $order */
    public function onStatusChanged(int $orderId, string $from, string $to, $order = null): void
    {
        $this->reconcile($orderId, $order instanceof WC_Order ? $order : null);
    }

    public function onRefunded(int $orderId, int $refundId): void
    {
        $this->reconcile($orderId, null);
    }

    public function reconcile(int $orderId, ?WC_Order $order): void
    {
        try {
            $order ??= wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                return;
            }
            $customerKey = $this->identityResolver->resolve($order);
            if (null === $customerKey) {
                return; // commande sans contact rattachable : pas de fidélité
            }

            $completed = $order->has_status('completed');
            $this->reconcile->handle(new ReconcileOrderLoyaltyCommand(
                orderId: $orderId,
                customerKey: $customerKey,
                targetPots: $completed ? $this->counter->countEligiblePots($order) : 0,
                targetRewards: $completed ? $this->counter->countRewardPots($order) : 0,
                createdBy: current_user_can('edit_shop_orders') ? get_current_user_id() : 0,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Fidélité : réconciliation de la commande échouée.', [
                'order_id' => $orderId,
                'exception' => $exception,
            ]);
        }
    }
}
