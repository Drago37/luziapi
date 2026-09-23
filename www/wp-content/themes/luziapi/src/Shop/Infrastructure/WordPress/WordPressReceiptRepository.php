<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Domain\ValueObject\Money;
use LuziApi\Shared\Infrastructure\Wp;
use LuziApi\Shop\Domain\Receipt\NewReceiptEntry;
use LuziApi\Shop\Domain\Receipt\ReceiptEntry;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use LuziApi\Shop\Domain\Receipt\ReceiptRepository;
use RuntimeException;
use wpdb;

final readonly class WordPressReceiptRepository implements ReceiptRepository
{
    public function __construct(
        private wpdb $database,
        private ShopSchemaManager $schema,
        private DateTimeZone $timezone,
    ) {
    }

    public function add(NewReceiptEntry $entry): ReceiptEntry
    {
        $table = $this->schema->tableName();
        $inserted = $this->database->insert($table, [
            'sequence_number' => null,
            'order_id'        => $entry->orderId,
            'occurred_at'     => $entry->occurredAt->format('Y-m-d H:i:s'),
            'amount_cents'    => $entry->amount->cents(),
            'currency'        => $entry->amount->currency(),
            'payment_method'  => $entry->paymentMethod,
            'entry_type'      => $entry->type->value,
            'description'     => $entry->description,
            'reversal_of_id'  => $entry->reversalOfId,
            'created_by'      => $entry->createdBy,
            'created_at'      => $entry->createdAt->format('Y-m-d H:i:s'),
        ], ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);

        if (false === $inserted) {
            throw new RuntimeException('Unable to store receipt entry: ' . $this->database->last_error);
        }

        $id = (int) $this->database->insert_id;
        if (false === $this->database->update($table, ['sequence_number' => $id], ['id' => $id], ['%d'], ['%d'])) {
            $this->database->delete($table, ['id' => $id], ['%d']);
            throw new RuntimeException('Unable to number receipt entry: ' . $this->database->last_error);
        }

        $stored = $this->find($id);
        if (null === $stored) {
            $this->database->delete($table, ['id' => $id], ['%d']);
            throw new RuntimeException('Stored receipt entry could not be read back.');
        }

        return $stored;
    }

    public function find(int $id): ?ReceiptEntry
    {
        $query = Wp::prepared(
            $this->database,
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE id = %d',
            $id,
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $this->map(Wp::row($row)) : null;
    }

    public function hasReversalFor(int $entryId): bool
    {
        $query = Wp::prepared(
            $this->database,
            'SELECT COUNT(*) FROM ' . $this->schema->tableName() . ' WHERE reversal_of_id = %d',
            $entryId,
        );

        return Wp::int($this->database->get_var($query)) > 0;
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $query = Wp::prepared(
            $this->database,
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE occurred_at >= %s AND occurred_at <= %s ORDER BY occurred_at DESC, sequence_number DESC',
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->map(...), Wp::rows($rows));
    }

    public function netTotalsByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, static fn (int $id): bool => $id > 0)));
        if ([] === $orderIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));
        $query = Wp::prepared(
            $this->database,
            'SELECT order_id, SUM(amount_cents) AS total FROM ' . $this->schema->tableName() . " WHERE order_id IN ({$placeholders}) GROUP BY order_id",
            ...$orderIds,
        );
        $rows = $this->database->get_results($query, ARRAY_A);
        $totals = [];

        foreach (Wp::rows($rows) as $row) {
            $totals[Wp::int($row['order_id'])] = Wp::int($row['total']);
        }

        return $totals;
    }

    public function deleteByOrderId(int $orderId): int
    {
        if ($orderId <= 0) {
            return 0;
        }
        $deleted = $this->database->delete($this->schema->tableName(), ['order_id' => $orderId], ['%d']);

        return false === $deleted ? 0 : (int) $deleted;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ReceiptEntry
    {
        return new ReceiptEntry(
            Wp::int($row['id']),
            Wp::int($row['sequence_number']),
            null === $row['order_id'] ? null : Wp::int($row['order_id']),
            new DateTimeImmutable(Wp::str($row['occurred_at']), $this->timezone),
            new Money(Wp::int($row['amount_cents']), Wp::str($row['currency'])),
            Wp::str($row['payment_method']),
            ReceiptEntryType::from(Wp::str($row['entry_type'])),
            Wp::str($row['description']),
            null === $row['reversal_of_id'] ? null : Wp::int($row['reversal_of_id']),
            Wp::int($row['created_by']),
            new DateTimeImmutable(Wp::str($row['created_at']), $this->timezone),
        );
    }
}
