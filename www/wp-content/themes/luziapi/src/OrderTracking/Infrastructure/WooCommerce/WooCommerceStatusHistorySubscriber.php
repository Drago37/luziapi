<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WooCommerce;

use LuziApi\OrderTracking\Application\Command\RecordOrderStatusChange\RecordOrderStatusChangeHandler;
use Psr\Log\LoggerInterface;

final readonly class WooCommerceStatusHistorySubscriber
{
    public function __construct(
        private RecordOrderStatusChangeHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'record'], 30, 4);
    }

    /** @param mixed $order */
    public function record(int $orderId, string $fromStatus, string $toStatus, $order = null): void
    {
        try {
            $this->handler->handle($orderId, $fromStatus, $toStatus);
        } catch (\Throwable) {
            // Journal de suivi = fonctionnalité annexe : une panne ici ne doit
            // jamais perturber le workflow de commande (statuts, stock, e-mails).
            $this->logger->error('Suivi commande : historisation du changement de statut échouée.', [
                'order_id' => $orderId,
                'to' => $toStatus,
            ]);
        }
    }
}
