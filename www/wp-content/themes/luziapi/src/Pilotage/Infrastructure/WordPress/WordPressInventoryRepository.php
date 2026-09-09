<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewHarvestLot;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;
use RuntimeException;
use Throwable;
use wpdb;

final readonly class WordPressInventoryRepository implements InventoryRepository
{
    public function __construct(
        private wpdb $database,
        private PilotageSchemaManager $schema,
        private DateTimeZone $timezone,
    ) {
    }

    public function createLotWithInitialStock(NewHarvestLot $lot): HarvestLot
    {
        $this->database->query('START TRANSACTION');

        try {
            $inserted = $this->database->insert($this->schema->lotsTableName(), [
                'code'            => $lot->lotNumber,
                'product_id'      => $lot->productId,
                'harvest_year'    => $lot->harvestYear(),
                'harvested_at'    => $lot->harvestedAt->format('Y-m-d'),
                'jarred_at'       => $lot->jarredAt->format('Y-m-d H:i:s'),
                'apiary_origin'   => $lot->apiaryOrigin,
                'variety'         => $lot->variety,
                'quantity_jarred' => $lot->quantityJarred,
                'stock_already_recorded' => $lot->stockAlreadyRecorded ? 1 : 0,
                'created_by'      => $lot->createdBy,
                'created_at'      => $lot->createdAt->format('Y-m-d H:i:s'),
            ], ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']);
            if (false === $inserted) {
                throw new RuntimeException('Unable to store harvest lot: ' . $this->database->last_error);
            }
            $lotId = (int) $this->database->insert_id;
            $this->addMovement(new NewStockMovement(
                $lot->productId,
                $lotId,
                null,
                null,
                $lot->jarredAt,
                $lot->quantityJarred,
                StockMovementType::Harvest,
                ($lot->stockAlreadyRecorded ? 'Initialisation du stock existant — lot ' : 'Mise en pots de la récolte — lot ') . $lot->lotNumber,
                'lot:' . $lotId . ':initial',
                $lot->createdBy,
                $lot->createdAt,
            ));
            $this->database->query('COMMIT');
        } catch (Throwable $exception) {
            $this->database->query('ROLLBACK');

            throw $exception;
        }

        $stored = $this->findLot($lotId);
        if (null === $stored) {
            throw new RuntimeException('Stored harvest lot could not be read back.');
        }

        return $stored;
    }

    public function findLot(int $id): ?HarvestLot
    {
        $query = $this->database->prepare($this->lotsQuery() . ' WHERE lots.id = %d', $id);
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $this->mapLot($row) : null;
    }

    public function lots(): array
    {
        $rows = $this->database->get_results($this->lotsQuery() . ' ORDER BY lots.harvest_year DESC, lots.id DESC', ARRAY_A);

        return array_map($this->mapLot(...), is_array($rows) ? $rows : []);
    }

    public function availableLotsForProduct(int $productId): array
    {
        $query = $this->database->prepare(
            $this->lotsQuery() . ' WHERE lots.product_id = %d AND COALESCE(balance.stock_remaining, 0) > 0 ORDER BY lots.harvest_year ASC, lots.id ASC',
            $productId,
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->mapLot(...), is_array($rows) ? $rows : []);
    }

    public function addMovement(NewStockMovement $movement): StockMovement
    {
        $table = $this->schema->stockMovementsTableName();
        $inserted = $this->database->insert($table, [
            'sequence_number' => null,
            'product_id'      => $movement->productId,
            'lot_id'          => $movement->lotId,
            'order_id'        => $movement->orderId,
            'order_item_id'   => $movement->orderItemId,
            'occurred_at'     => $movement->occurredAt->format('Y-m-d H:i:s'),
            'quantity_delta'  => $movement->quantityDelta,
            'movement_type'   => $movement->type->value,
            'reason'          => $movement->reason,
            'reference_key'   => $movement->referenceKey,
            'created_by'      => $movement->createdBy,
            'created_at'      => $movement->createdAt->format('Y-m-d H:i:s'),
        ], ['%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s']);
        if (false === $inserted) {
            throw new RuntimeException('Unable to store stock movement: ' . $this->database->last_error);
        }

        $id = (int) $this->database->insert_id;
        if (false === $this->database->update($table, ['sequence_number' => $id], ['id' => $id], ['%d'], ['%d'])) {
            $this->database->delete($table, ['id' => $id], ['%d']);
            throw new RuntimeException('Unable to number stock movement: ' . $this->database->last_error);
        }

        $query = $this->database->prepare("SELECT * FROM {$table} WHERE id = %d", $id);
        $row = $this->database->get_row($query, ARRAY_A);
        if (! is_array($row)) {
            $this->database->delete($table, ['id' => $id], ['%d']);
            throw new RuntimeException('Stored stock movement could not be read back.');
        }

        return $this->mapMovement($row);
    }

    public function hasMovementReference(string $referenceKey): bool
    {
        $query = $this->database->prepare(
            'SELECT COUNT(*) FROM ' . $this->schema->stockMovementsTableName() . ' WHERE reference_key = %s',
            $referenceKey,
        );

        return (int) $this->database->get_var($query) > 0;
    }

    public function orderMovements(int $orderId, int $orderItemId, StockMovementType $type): array
    {
        $query = $this->database->prepare(
            'SELECT * FROM ' . $this->schema->stockMovementsTableName() . ' WHERE order_id = %d AND order_item_id = %d AND movement_type = %s ORDER BY id ASC',
            $orderId,
            $orderItemId,
            $type->value,
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->mapMovement(...), is_array($rows) ? $rows : []);
    }

    public function orderItemMovements(int $orderId, int $orderItemId): array
    {
        $query = $this->database->prepare(
            'SELECT * FROM ' . $this->schema->stockMovementsTableName() . ' WHERE order_id = %d AND order_item_id = %d ORDER BY id ASC',
            $orderId,
            $orderItemId,
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->mapMovement(...), is_array($rows) ? $rows : []);
    }

    public function recentMovements(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->database->prepare(
            'SELECT * FROM ' . $this->schema->stockMovementsTableName() . ' ORDER BY occurred_at DESC, id DESC LIMIT %d',
            $limit,
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        return array_map($this->mapMovement(...), is_array($rows) ? $rows : []);
    }

    private function lotsQuery(): string
    {
        return 'SELECT lots.*, COALESCE(balance.stock_remaining, 0) AS stock_remaining FROM '
            . $this->schema->lotsTableName()
            . ' lots LEFT JOIN (SELECT lot_id, SUM(quantity_delta) AS stock_remaining FROM '
            . $this->schema->stockMovementsTableName()
            . ' WHERE lot_id IS NOT NULL GROUP BY lot_id) balance ON balance.lot_id = lots.id';
    }

    /** @param array<string, mixed> $row */
    private function mapLot(array $row): HarvestLot
    {
        return new HarvestLot(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['product_id'],
            (int) $row['harvest_year'],
            new DateTimeImmutable((string) $row['harvested_at'], $this->timezone),
            new DateTimeImmutable((string) $row['jarred_at'], $this->timezone),
            (string) $row['apiary_origin'],
            (string) $row['variety'],
            (int) $row['quantity_jarred'],
            (int) $row['stock_remaining'],
            1 === (int) $row['stock_already_recorded'],
            (int) $row['created_by'],
            new DateTimeImmutable((string) $row['created_at'], $this->timezone),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapMovement(array $row): StockMovement
    {
        return new StockMovement(
            (int) $row['id'],
            (int) $row['sequence_number'],
            (int) $row['product_id'],
            null === $row['lot_id'] ? null : (int) $row['lot_id'],
            null === $row['order_id'] ? null : (int) $row['order_id'],
            null === $row['order_item_id'] ? null : (int) $row['order_item_id'],
            new DateTimeImmutable((string) $row['occurred_at'], $this->timezone),
            (int) $row['quantity_delta'],
            StockMovementType::from((string) $row['movement_type']),
            (string) $row['reason'],
            null === $row['reference_key'] ? null : (string) $row['reference_key'],
            (int) $row['created_by'],
            new DateTimeImmutable((string) $row['created_at'], $this->timezone),
        );
    }
}
