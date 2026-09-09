<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Activity\ActivityEntry;
use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use LuziApi\Pilotage\Domain\Activity\ActivityRepository;
use LuziApi\Pilotage\Domain\Activity\NewActivityEntry;
use RuntimeException;
use wpdb;

final readonly class WordPressActivityRepository implements ActivityRepository
{
    public function __construct(
        private wpdb $database,
        private PilotageSchemaManager $schema,
        private DateTimeZone $timezone,
    ) {
    }

    public function add(NewActivityEntry $entry): ActivityEntry
    {
        try {
            $details = json_encode($entry->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode activity details.', 0, $exception);
        }

        $inserted = $this->database->insert($this->schema->activityTableName(), [
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            'actor_id'    => $entry->actorId,
            'category'    => $entry->category->value,
            'action'      => $entry->action,
            'object_type' => $entry->objectType,
            'object_id'   => $entry->objectId,
            'summary'     => $entry->summary,
            'details_json' => $details,
        ], ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);
        if (false === $inserted) {
            throw new RuntimeException('Unable to store activity: ' . $this->database->last_error);
        }

        return new ActivityEntry(
            (int) $this->database->insert_id,
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
        $where = ['occurred_at >= %s', 'occurred_at <= %s'];
        $arguments = [$filter->from->format('Y-m-d H:i:s'), $filter->to->format('Y-m-d H:i:s')];
        if ($filter->category instanceof ActivityCategory) {
            $where[] = 'category = %s';
            $arguments[] = $filter->category->value;
        }
        if ('' !== $filter->search) {
            $like = '%' . $this->database->esc_like($filter->search) . '%';
            $where[] = '(summary LIKE %s OR action LIKE %s OR object_type LIKE %s OR CAST(object_id AS CHAR) LIKE %s OR details_json LIKE %s)';
            array_push($arguments, $like, $like, $like, $like, $like);
        }
        $limit = max(1, min(2000, $filter->limit));
        $arguments[] = $limit;
        $query = $this->database->prepare(
            'SELECT * FROM ' . $this->schema->activityTableName() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY occurred_at DESC, id DESC LIMIT %d',
            ...$arguments,
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->map(...), is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ActivityEntry
    {
        $details = json_decode((string) $row['details_json'], true);
        $normalizedDetails = [];
        if (is_array($details)) {
            foreach ($details as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $normalizedDetails[$key] = (string) $value;
                }
            }
        }

        return new ActivityEntry(
            (int) $row['id'],
            new DateTimeImmutable((string) $row['occurred_at'], $this->timezone),
            (int) $row['actor_id'],
            ActivityCategory::from((string) $row['category']),
            (string) $row['action'],
            (string) $row['object_type'],
            null === $row['object_id'] ? null : (int) $row['object_id'],
            (string) $row['summary'],
            $normalizedDetails,
        );
    }
}
