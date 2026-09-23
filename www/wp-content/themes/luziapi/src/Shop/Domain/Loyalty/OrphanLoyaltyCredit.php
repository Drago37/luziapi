<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Loyalty;

/**
 * Écriture de fidélité rattachée à une commande **disparue** (supprimée) alors
 * qu'elle porte encore un solde net de pots positif : le crédit aurait dû être
 * contre-passé. Analogue « pots » de la recette orpheline.
 */
final readonly class OrphanLoyaltyCredit
{
    public function __construct(
        public int $orderId,
        public int $netPots,
    ) {
    }
}
