<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

final readonly class GetLoyaltyDashboardQuery
{
    /**
     * @param string|null $period période à afficher :
     *                            - `null` ou `last2` : les 2 dernières années (défaut, plus représentatif) ;
     *                            - `all` : toutes les années cumulées ;
     *                            - une année à 4 chiffres (ex. `2026`) : cette année seule.
     */
    public function __construct(
        public ?string $period = null,
        public int $topSize = 5,
    ) {
    }
}
