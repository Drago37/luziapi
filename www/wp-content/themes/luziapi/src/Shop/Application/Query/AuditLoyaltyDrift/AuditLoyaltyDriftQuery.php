<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\AuditLoyaltyDrift;

final readonly class AuditLoyaltyDriftQuery
{
    /**
     * @param int|null $year année civile à auditer pour les trous de crédit ; null =
     *                       tout l'historique (de la première commande à maintenant).
     *                       Les crédits orphelins sont toujours audités sur tout le journal.
     */
    public function __construct(
        public ?int $year = null,
    ) {
    }
}
