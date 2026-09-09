<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Activity;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Activity\ActivityRepository;
use LuziApi\Pilotage\Domain\Activity\NewActivityEntry;
use Throwable;

final readonly class ActivityRecorder
{
    public function __construct(
        private ActivityRepository $activities,
        private Clock $clock,
    ) {
    }

    /** @param array<string, string> $details */
    public function record(
        ActivityCategory $category,
        string $action,
        string $objectType,
        ?int $objectId,
        string $summary,
        array $details = [],
        int $actorId = 0,
    ): void {
        try {
            $this->activities->add(new NewActivityEntry(
                $this->clock->now(),
                $actorId,
                $category,
                $action,
                $objectType,
                $objectId,
                $summary,
                $details,
            ));
        } catch (Throwable) {
            // Le journal ne doit jamais bloquer l'opération métier qu'il observe.
        }
    }
}
