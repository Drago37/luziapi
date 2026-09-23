<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetActivityLog;

use LuziApi\Shop\Domain\Activity\ActivityFilter;
use LuziApi\Shop\Domain\Activity\ActivityRepository;

final readonly class GetActivityLogHandler
{
    public function __construct(private ActivityRepository $activities)
    {
    }

    /** @return list<\LuziApi\Shop\Domain\Activity\ActivityEntry> */
    public function handle(ActivityFilter $filter): array
    {
        return $this->activities->search($filter);
    }
}
