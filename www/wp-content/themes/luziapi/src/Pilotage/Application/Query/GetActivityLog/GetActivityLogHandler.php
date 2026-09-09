<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetActivityLog;

use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use LuziApi\Pilotage\Domain\Activity\ActivityRepository;

final readonly class GetActivityLogHandler
{
    public function __construct(private ActivityRepository $activities)
    {
    }

    /** @return list<\LuziApi\Pilotage\Domain\Activity\ActivityEntry> */
    public function handle(ActivityFilter $filter): array
    {
        return $this->activities->search($filter);
    }
}
