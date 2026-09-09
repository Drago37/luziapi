<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetAnnualDashboard;

final readonly class GetAnnualDashboardQuery
{
    public function __construct(public ?int $year = null)
    {
    }
}
