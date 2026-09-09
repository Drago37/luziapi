<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Activity\ActivityEntry;
use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use LuziApi\Pilotage\Domain\Activity\ActivityRepository;
use LuziApi\Pilotage\Domain\Activity\NewActivityEntry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActivityRecorderTest extends TestCase
{
    public function testItRecordsTheBusinessEventWithItsActorAndDetails(): void
    {
        $repository = new ActivityRepositorySpy();
        $recorder = new ActivityRecorder($repository, new ActivityClock());

        $recorder->record(
            ActivityCategory::Harvest,
            'harvest_created',
            'harvest_lot',
            12,
            'Récolte créée',
            ['Lot' => 'L2026-01'],
            4,
        );

        self::assertCount(1, $repository->added);
        self::assertSame('2026-09-09 11:30:00', $repository->added[0]->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame(ActivityCategory::Harvest, $repository->added[0]->category);
        self::assertSame(4, $repository->added[0]->actorId);
        self::assertSame(['Lot' => 'L2026-01'], $repository->added[0]->details);
    }

    public function testJournalFailureNeverBlocksTheObservedBusinessOperation(): void
    {
        $recorder = new ActivityRecorder(new FailingActivityRepository(), new ActivityClock());

        $recorder->record(
            ActivityCategory::Order,
            'order_updated',
            'order',
            123,
            'Commande mise à jour',
        );

        self::assertTrue(true);
    }
}

final class ActivityClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09 11:30:00', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}

final class ActivityRepositorySpy implements ActivityRepository
{
    /** @var list<NewActivityEntry> */
    public array $added = [];

    public function add(NewActivityEntry $entry): ActivityEntry
    {
        $this->added[] = $entry;

        return new ActivityEntry(
            count($this->added),
            $entry->occurredAt,
            $entry->actorId,
            $entry->category,
            $entry->action,
            $entry->objectType,
            $entry->objectId,
            $entry->summary,
            $entry->details,
        );
    }

    public function search(ActivityFilter $filter): array
    {
        return [];
    }
}

final class FailingActivityRepository implements ActivityRepository
{
    public function add(NewActivityEntry $entry): ActivityEntry
    {
        throw new RuntimeException('Database unavailable.');
    }

    public function search(ActivityFilter $filter): array
    {
        return [];
    }
}
