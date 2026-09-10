<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots;

/**
 * Ajustement manuel du solde de pots d'un client (geste, correction).
 * `pots` peut être négatif (retrait). `customerKey` est l'une des clés d'identité
 * du profil (le solde agrège toutes ses clés).
 */
final readonly class AdjustLoyaltyPotsCommand
{
    public function __construct(
        public string $customerKey,
        public int $pots,
        public string $reason,
        public int $createdBy,
    ) {
    }
}
