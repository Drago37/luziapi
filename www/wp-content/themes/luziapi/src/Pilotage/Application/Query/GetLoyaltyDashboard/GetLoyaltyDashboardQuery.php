<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

final readonly class GetLoyaltyDashboardQuery
{
    /**
     * @param int|null $year année civile à afficher, ou `null` pour l'année en cours
     */
    public function __construct(
        public ?int $year = null,
        public int $topSize = 5,
    ) {
    }
}
