<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Loyalty\Domain\LoyaltyEntry;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;
use RuntimeException;
use wpdb;

final readonly class WordPressLoyaltyLedger implements LoyaltyLedger
{
    public function __construct(
        private wpdb $database,
        private LoyaltySchemaManager $schema,
        private DateTimeZone $timezone,
    ) {
    }

    public function append(NewLoyaltyEntry $entry): ?int
    {
        // Colonnes en dur (jamais d'entrée utilisateur). Les valeurs `null` sont
        // émises comme littéral SQL `NULL` : `wpdb::prepare()` transformerait un
        // `null` en '' / 0 et corromprait les colonnes nullables.
        $fields = [
            'customer_key' => [$entry->customerKey, '%s'],
            'entry_type' => [$entry->type->value, '%s'],
            'pots_delta' => [$entry->potsDelta, '%d'],
            'rights_delta' => [$entry->rightsDelta, '%d'],
            'source_order_id' => [$entry->sourceOrderId, '%d'],
            'usage_order_id' => [$entry->usageOrderId, '%d'],
            'reversal_of_id' => [$entry->reversalOfId, '%d'],
            'idempotency_key' => [$entry->idempotencyKey, '%s'],
            'reason' => [$entry->reason, '%s'],
            'created_by' => [$entry->createdBy, '%d'],
            'occurred_at' => [$entry->occurredAt->format('Y-m-d H:i:s'), '%s'],
            'created_at' => [$entry->createdAt->format('Y-m-d H:i:s'), '%s'],
        ];
        $columns = [];
        $placeholders = [];
        $args = [];
        foreach ($fields as $name => [$value, $spec]) {
            $columns[] = $name;
            if (null === $value) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = $spec;
            $args[] = $value;
        }

        // INSERT IGNORE : un doublon d'idempotency_key est silencieusement ignoré
        // (0 ligne affectée), garantissant l'idempotence sans lever d'erreur.
        $sql = 'INSERT IGNORE INTO ' . $this->schema->ledgerTableName()
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $result = $this->database->query($this->database->prepare($sql, ...$args));
        if (false === $result) {
            throw new RuntimeException('Unable to append the loyalty ledger entry.');
        }
        if (0 === (int) $result) {
            return null; // doublon idempotent ignoré
        }

        return (int) $this->database->insert_id;
    }

    public function hasEntryForIdempotencyKey(string $idempotencyKey): bool
    {
        $found = $this->database->get_var($this->database->prepare(
            'SELECT id FROM ' . $this->schema->ledgerTableName() . ' WHERE idempotency_key = %s LIMIT 1',
            $idempotencyKey,
        ));

        return null !== $found;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?LoyaltyEntry
    {
        $row = $this->database->get_row($this->database->prepare(
            'SELECT * FROM ' . $this->schema->ledgerTableName() . ' WHERE idempotency_key = %s LIMIT 1',
            $idempotencyKey,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function orderTotals(int $orderId): array
    {
        $row = $this->database->get_row($this->database->prepare(
            'SELECT COALESCE(SUM(pots_delta), 0) AS pots, COALESCE(SUM(rights_delta), 0) AS rights'
            . ' FROM ' . $this->schema->ledgerTableName() . ' WHERE source_order_id = %d',
            $orderId,
        ), ARRAY_A);

        return ['pots' => (int) ($row['pots'] ?? 0), 'rights' => (int) ($row['rights'] ?? 0)];
    }

    public function totalsForCustomerKeys(array $customerKeys, ?\DateTimeImmutable $potsSince = null): array
    {
        $keys = $this->sanitizeKeys($customerKeys);
        if ([] === $keys) {
            return ['pots' => 0, 'rightsConsumed' => 0, 'entryCount' => 0];
        }

        [$potsExpr, $sinceArgs] = $this->potsExpression($potsSince);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $row = $this->database->get_row($this->database->prepare(
            'SELECT ' . $potsExpr . ' AS pots,'
            . ' COALESCE(SUM(rights_delta), 0) AS rights,'
            . ' COUNT(*) AS entry_count'
            . ' FROM ' . $this->schema->ledgerTableName()
            . " WHERE customer_key IN ({$placeholders})",
            ...[...$sinceArgs, ...$keys],
        ), ARRAY_A);

        return [
            'pots' => (int) ($row['pots'] ?? 0),
            'rightsConsumed' => max(0, -(int) ($row['rights'] ?? 0)),
            'entryCount' => (int) ($row['entry_count'] ?? 0),
        ];
    }

    public function balancesByCustomerKeys(array $customerKeys, ?\DateTimeImmutable $potsSince = null): array
    {
        $keys = $this->sanitizeKeys($customerKeys);
        if ([] === $keys) {
            return [];
        }

        [$potsExpr, $sinceArgs] = $this->potsExpression($potsSince);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            'SELECT customer_key,'
            . ' ' . $potsExpr . ' AS pots,'
            . ' COALESCE(SUM(rights_delta), 0) AS rights'
            . ' FROM ' . $this->schema->ledgerTableName()
            . " WHERE customer_key IN ({$placeholders})"
            . ' GROUP BY customer_key',
            ...[...$sinceArgs, ...$keys],
        ), ARRAY_A);

        $balances = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $balances[(string) $row['customer_key']] = [
                'pots' => (int) $row['pots'],
                'rights' => (int) $row['rights'],
            ];
        }

        return $balances;
    }

    public function entriesForCustomerKeys(array $customerKeys, int $limit = 50): array
    {
        $keys = $this->sanitizeKeys($customerKeys);
        if ([] === $keys) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            'SELECT * FROM ' . $this->schema->ledgerTableName()
            . " WHERE customer_key IN ({$placeholders})"
            . ' ORDER BY occurred_at DESC, id DESC LIMIT %d',
            ...[...$keys, $limit],
        ), ARRAY_A);

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    /**
     * Expression SQL de la somme des pots (bornée à `$potsSince` si fourni) et les
     * arguments préparés correspondants, à placer AVANT ceux du `IN (...)`.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function potsExpression(?\DateTimeImmutable $potsSince): array
    {
        if (null === $potsSince) {
            return ['COALESCE(SUM(pots_delta), 0)', []];
        }

        return [
            'COALESCE(SUM(CASE WHEN occurred_at >= %s THEN pots_delta ELSE 0 END), 0)',
            [$potsSince->format('Y-m-d H:i:s')],
        ];
    }

    /**
     * @param list<string> $customerKeys
     *
     * @return list<string>
     */
    private function sanitizeKeys(array $customerKeys): array
    {
        return array_values(array_unique(array_filter(
            $customerKeys,
            static fn (string $key): bool => '' !== $key,
        )));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LoyaltyEntry
    {
        return new LoyaltyEntry(
            id: (int) $row['id'],
            customerKey: (string) $row['customer_key'],
            type: LoyaltyEntryType::from((string) $row['entry_type']),
            potsDelta: (int) $row['pots_delta'],
            rightsDelta: (int) $row['rights_delta'],
            sourceOrderId: null !== $row['source_order_id'] ? (int) $row['source_order_id'] : null,
            usageOrderId: null !== $row['usage_order_id'] ? (int) $row['usage_order_id'] : null,
            reversalOfId: null !== $row['reversal_of_id'] ? (int) $row['reversal_of_id'] : null,
            idempotencyKey: (string) $row['idempotency_key'],
            reason: (string) ($row['reason'] ?? ''),
            createdBy: (int) $row['created_by'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at'], $this->timezone),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $this->timezone),
        );
    }
}
