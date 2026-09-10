<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\RecordCompletedOrder;

/**
 * Crédite les pots éligibles d'une commande passée « Terminée ».
 *
 * `customerKey` est la clé d'identité fidélité (voir `LoyaltyIdentity`) ;
 * `pots` le nombre de pots éligibles déjà comptés sur la commande (quantités
 * des produits admissibles, hors lignes offertes, ajusté des remboursements).
 * `createdBy` est l'utilisateur WordPress à l'origine, ou 0 pour l'automate.
 */
final readonly class RecordCompletedOrderCommand
{
    public function __construct(
        public int $orderId,
        public string $customerKey,
        public int $pots,
        public int $createdBy = 0,
    ) {
    }
}
