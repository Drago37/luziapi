<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

final readonly class GetLoyaltyDashboardQuery
{
    public function __construct(public int $topSize = 5)
    {
    }
}
