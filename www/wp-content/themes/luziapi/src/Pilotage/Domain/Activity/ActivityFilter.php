<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Activity;

use DateTimeImmutable;

final readonly class ActivityFilter
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public ?ActivityCategory $category,
        public string $search,
        public int $limit = 500,
    ) {
    }
}
