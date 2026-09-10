<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionCommand;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditCommand;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditHandler;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionCommand;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionHandler;
use Psr\Log\LoggerInterface;
use WC_Order;

/**
 * Moteur d'acquisition ET de consommation de la fidélité, branché sur le cycle de
 * vie des commandes.
 *
 * Règle métier : une commande « Terminée » = encaissée. Au passage « Terminée » on
 * crédite les pots gagnés ET on décompte les avantages utilisés (pots offerts au
 * titre de la fidélité). Si la commande quitte cet état (annulation, remboursement,
 * retour en attente), les deux mouvements sont contre-passés. Les ventes rapides
 * comptent comme les autres. Toute panne ici est journalisée mais ne perturbe
 * jamais le workflow de commande.
 */
final readonly class WooCommerceLoyaltyEarningSubscriber
{
    public function __construct(
        private RecordCompletedOrderHandler $recordHandler,
        private ReverseOrderCreditHandler $reverseHandler,
        private RecordRewardConsumptionHandler $rewardHandler,
        private ReverseRewardConsumptionHandler $reverseRewardHandler,
        private WooCommerceEligiblePotCounter $counter,
        private WooCommerceOrderIdentityResolver $identityResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_completed', [$this, 'orderCompleted'], 100, 2);
        add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], 30, 4);
    }

    /** @param mixed $order */
    public function orderCompleted(int $orderId, $order = null): void
    {
        try {
            $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                return;
            }

            $customerKey = $this->identityResolver->resolve($order);
            if (null === $customerKey) {
                return; // commande sans contact rattachable : pas de fidélité
            }

            $actorId = current_user_can('edit_shop_orders') ? get_current_user_id() : 0;

            $this->recordHandler->handle(new RecordCompletedOrderCommand(
                orderId: $orderId,
                customerKey: $customerKey,
                pots: $this->counter->countEligiblePots($order),
                createdBy: $actorId,
            ));
            $this->rewardHandler->handle(new RecordRewardConsumptionCommand(
                orderId: $orderId,
                customerKey: $customerKey,
                rewards: $this->counter->countRewardPots($order),
                createdBy: $actorId,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Fidélité : traitement de la commande terminée échoué.', [
                'order_id' => $orderId,
                'exception' => $exception,
            ]);
        }
    }

    /** @param mixed $order */
    public function orderStatusChanged(int $orderId, string $fromStatus, string $toStatus, $order = null): void
    {
        // On ne contre-passe qu'en SORTIE de « Terminée ».
        if ('completed' !== $fromStatus || 'completed' === $toStatus) {
            return;
        }

        try {
            $actorId = current_user_can('edit_shop_orders') ? get_current_user_id() : 0;
            $reason = sprintf('Commande #%d : passage « Terminée » → « %s »', $orderId, $toStatus);

            $this->reverseHandler->handle(new ReverseOrderCreditCommand(
                orderId: $orderId,
                reason: $reason,
                createdBy: $actorId,
            ));
            $this->reverseRewardHandler->handle(new ReverseRewardConsumptionCommand(
                orderId: $orderId,
                reason: $reason,
                createdBy: $actorId,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Fidélité : contre-passation des mouvements échouée.', [
                'order_id' => $orderId,
                'to' => $toStatus,
                'exception' => $exception,
            ]);
        }
    }
}
