<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Règle métier LuziApi : une commande mise à la **corbeille** ou **supprimée**
 * n'est plus un encaissement — sa recette doit sortir du registre. Sans quoi le
 * registre garderait de l'« argent fantôme » (recettes orphelines) qui gonfle le
 * total encaissé au-dessus du chiffre réellement vendu.
 *
 * On **supprime** les lignes de recette de la commande (pas de contre-passe : la
 * commande n'existe plus, une écriture compensatrice n'aurait rien à référencer).
 * La suppression est idempotente (0 ligne au second passage), donc le double
 * déclenchement corbeille + suppression définitive est sans effet de bord.
 */
final readonly class WooCommerceOrphanReceiptSubscriber
{
    public function __construct(
        private ReceiptRepository $receipts,
        private LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_trash_order', [$this, 'onOrderGone'], 10, 1);
        add_action('woocommerce_before_delete_order', [$this, 'onOrderGone'], 10, 1);
    }

    public function onOrderGone(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            $removed = $this->receipts->deleteByOrderId($orderId);
            if ($removed > 0) {
                $this->logger->info('Recette(s) retirée(s) : commande mise à la corbeille ou supprimée.', [
                    'order_id' => $orderId,
                    'removed'  => $removed,
                ]);
            }
        } catch (Throwable $exception) {
            // Ne jamais empêcher la corbeille/suppression de la commande.
            $this->logger->error('Retrait des recettes de la commande supprimée échoué.', [
                'order_id'  => $orderId,
                'exception' => $exception,
            ]);
        }
    }
}
