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
    /** Méta de commande qui exclut définitivement une commande de la fidélité. */
    public const LOYALTY_EXCLUDED_META = '_luziapi_loyalty_excluded';

    /** Préfixe du transient (par utilisateur) signalant un recalcul admin échoué. */
    private const ADMIN_NOTICE_TRANSIENT = 'luziapi_loyalty_reconcile_failed_';

    public function __construct(
        private ReconcileOrderLoyaltyHandler $reconcile,
        private EligiblePotCounter $counter,
        private OrderIdentityResolver $identityResolver,
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
        // Recalcul CIBLÉ : uniquement quand la case « Exclure de la fidélité » change
        // (action émise par le save de la fiche commande), et NON à chaque édition —
        // sinon corriger une adresse sur une vieille commande la recalculerait sur la
        // config produit actuelle et pourrait retirer des pots légitimement gagnés.
        add_action('luziapi_loyalty_exclusion_changed', [$this, 'onExclusionChanged'], 10, 2);
        // Un opérateur est présent lors du changement : lui afficher un avis si le
        // recalcul a échoué (sinon l'échec ne vit que dans le journal Monolog).
        add_action('admin_notices', [$this, 'renderReconcileFailureNotice']);
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

    /**
     * La case « Exclure de la fidélité » vient de changer sur une commande : on
     * recalcule. Comme {@see reconcile()}, l'échec ne doit pas casser l'enregistrement,
     * mais un opérateur est présent — on lui laisse en plus un avis (transient) pour
     * qu'il sache que le solde de pots peut être incohérent.
     *
     * @param mixed $order
     */
    public function onExclusionChanged(int $orderId, $order = null): void
    {
        try {
            $this->reconcileToTarget($orderId, $order instanceof WC_Order ? $order : null);
        } catch (\Throwable $exception) {
            $this->logException($orderId, $exception);
            set_transient(self::ADMIN_NOTICE_TRANSIENT . get_current_user_id(), $orderId, 120);
        }
    }

    public function reconcile(int $orderId, ?WC_Order $order): void
    {
        try {
            $this->reconcileToTarget($orderId, $order);
        } catch (\Throwable $exception) {
            $this->logException($orderId, $exception);
        }
    }

    /**
     * Affiche, une seule fois, l'avis d'échec de recalcul déposé par
     * {@see onExclusionChanged()} pour l'utilisateur courant.
     */
    public function renderReconcileFailureNotice(): void
    {
        $key = self::ADMIN_NOTICE_TRANSIENT . get_current_user_id();
        $orderId = (int) get_transient($key);
        if ($orderId <= 0) {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                'Fidélité : le recalcul de la commande #%d a échoué — le solde de pots peut être incohérent. Voir le journal.',
                $orderId,
            )),
        );
    }

    /** @throws \Throwable la réconciliation n'est pas rattrapée ici (voir les appelants). */
    private function reconcileToTarget(int $orderId, ?WC_Order $order): void
    {
        $order ??= wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        $customerKey = $this->identityResolver->resolve($order);
        if (null === $customerKey) {
            return; // commande sans contact rattachable : pas de fidélité
        }

        // Garde : une commande explicitement exclue (ex. import d'historique)
        // ne cumule jamais de fidélité — cible à zéro quel que soit son statut,
        // ce qui la maintient sans crédit même si un hook se déclenche plus tard.
        $excluded = 'yes' === (string) $order->get_meta(self::LOYALTY_EXCLUDED_META);

        $completed = $order->has_status('completed');
        $this->reconcile->handle(new ReconcileOrderLoyaltyCommand(
            orderId: $orderId,
            customerKey: $customerKey,
            targetPots: (! $excluded && $completed) ? $this->counter->countEligiblePots($order) : 0,
            targetRewards: (! $excluded && $completed) ? $this->counter->countRewardPots($order) : 0,
            createdBy: current_user_can('edit_shop_orders') ? get_current_user_id() : 0,
        ));
    }

    private function logException(int $orderId, \Throwable $exception): void
    {
        $this->logger->error('Fidélité : réconciliation de la commande échouée.', [
            'order_id' => $orderId,
            'exception' => $exception,
        ]);
    }
}
