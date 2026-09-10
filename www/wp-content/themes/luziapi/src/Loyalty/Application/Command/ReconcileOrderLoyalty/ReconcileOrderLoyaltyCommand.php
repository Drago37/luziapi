<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty;

/**
 * Réconcilie le journal de fidélité pour une commande : porte les pots crédités et
 * les avantages consommés au niveau CIBLE de la commande (ses pots éligibles et
 * ses pots offerts fidélité si elle est « Terminée », zéro sinon). Le handler
 * n'écrit que l'écart — d'où un traitement uniforme des remboursements partiels,
 * totaux, annulations et re-complétions.
 */
final readonly class ReconcileOrderLoyaltyCommand
{
    public function __construct(
        public int $orderId,
        public string $customerKey,
        public int $targetPots,
        public int $targetRewards,
        public int $createdBy = 0,
    ) {
    }
}
