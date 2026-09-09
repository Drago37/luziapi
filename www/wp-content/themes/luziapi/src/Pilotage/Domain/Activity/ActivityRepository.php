<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Activity;

interface ActivityRepository
{
    public function add(NewActivityEntry $entry): ActivityEntry;

    /** @return list<ActivityEntry> */
    public function search(ActivityFilter $filter): array;
}
