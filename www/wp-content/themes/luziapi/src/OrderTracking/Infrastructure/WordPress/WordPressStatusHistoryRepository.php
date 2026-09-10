<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\OrderTracking\Domain\StatusHistoryRepository;
use LuziApi\OrderTracking\Domain\StatusTransition;
use RuntimeException;
use wpdb;

final readonly class WordPressStatusHistoryRepository implements StatusHistoryRepository
{
    public function __construct(
        private wpdb $database,
        private OrderTrackingSchemaManager $schema,
        private DateTimeZone $timezone,
    ) {
    }

    public function record(StatusTransition $transition): void
    {
        $saved = $this->database->query($this->database->prepare(
            'INSERT IGNORE INTO ' . $this->schema->eventsTableName()
            . ' (order_id, from_status, to_status, occurred_at, reference_key) VALUES (%d, %s, %s, %s, %s)',
            $transition->orderId,
            $transition->fromStatus,
            $transition->toStatus,
            $transition->occurredAt->format('Y-m-d H:i:s'),
            $transition->referenceKey,
        ));
        if (false === $saved) {
            throw new RuntimeException('Unable to record the public order status history.');
        }
    }

    public function forOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn (int $id): bool => $id > 0)));
        if ([] === $orderIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));
        $query = $this->database->prepare(
            'SELECT order_id, from_status, to_status, occurred_at, reference_key FROM '
            . $this->schema->eventsTableName()
            . " WHERE order_id IN ({$placeholders}) ORDER BY occurred_at ASC, id ASC",
            ...$orderIds,
        );
        $rows = $this->database->get_results($query, ARRAY_A);
        $history = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $orderId = (int) $row['order_id'];
            $history[$orderId][] = new StatusTransition(
                $orderId,
                (string) $row['from_status'],
                (string) $row['to_status'],
                new DateTimeImmutable((string) $row['occurred_at'], $this->timezone),
                (string) $row['reference_key'],
            );
        }

        return $history;
    }
}
