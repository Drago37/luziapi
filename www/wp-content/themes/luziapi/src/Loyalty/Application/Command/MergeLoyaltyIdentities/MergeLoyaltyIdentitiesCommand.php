<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities;

/**
 * Fusionne deux clients de fidélité : toutes leurs clés d'identité sont rattachées
 * au même groupe. Chaque client est décrit par ses clés (tous ses `identityIds`).
 */
final readonly class MergeLoyaltyIdentitiesCommand
{
    /**
     * @param list<string> $keysA clés d'identité du premier client
     * @param list<string> $keysB clés d'identité du second client
     */
    public function __construct(
        public array $keysA,
        public array $keysB,
    ) {
    }
}
