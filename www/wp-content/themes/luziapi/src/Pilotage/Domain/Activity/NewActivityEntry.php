<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Activity;

use DateTimeImmutable;

final readonly class NewActivityEntry
{
    /** @param array<string, string> $details */
    public function __construct(
        public DateTimeImmutable $occurredAt,
        public int $actorId,
        public ActivityCategory $category,
        public string $action,
        public string $objectType,
        public ?int $objectId,
        public string $summary,
        public array $details = [],
    ) {
    }
}
