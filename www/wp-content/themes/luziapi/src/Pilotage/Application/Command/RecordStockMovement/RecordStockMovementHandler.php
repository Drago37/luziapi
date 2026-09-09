<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordStockMovement;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\StockLevelGateway;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewStockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;
use Throwable;

final readonly class RecordStockMovementHandler
{
    public function __construct(
        private InventoryRepository $inventory,
        private StockLevelGateway $stock,
        private Clock $clock,
    ) {
    }

    public function handle(RecordStockMovementCommand $command): StockMovement
    {
        if (! $command->type->isManual() || 0 === $command->quantity) {
            throw new InvalidArgumentException('Invalid manual stock movement.');
        }
        if (StockMovementType::Correction !== $command->type && $command->quantity < 0) {
            throw new InvalidArgumentException('Manual output quantity must be positive.');
        }
        if (StockMovementType::Correction === $command->type && '' === trim($command->reason)) {
            throw new InvalidArgumentException('Inventory correction requires a reason.');
        }
        if ($command->occurredAt > $this->clock->now()->modify('+5 minutes')) {
            throw new InvalidArgumentException('Stock movement date cannot be in the future.');
        }

        $delta = StockMovementType::Correction === $command->type
            ? $command->quantity
            : -$command->quantity;
        if (null !== $command->lotId) {
            $lot = $this->inventory->findLot($command->lotId);
            if (null === $lot || $lot->productId !== $command->productId) {
                throw new InvalidArgumentException('Lot does not belong to selected product.');
            }
            if ($delta < 0 && $lot->stockRemaining < abs($delta)) {
                throw new InvalidArgumentException('Lot stock is insufficient.');
            }
        } elseif ($delta < 0 && [] !== $this->inventory->availableLotsForProduct($command->productId)) {
            throw new InvalidArgumentException('A lot must be selected for this stock output.');
        }

        $reason = '' !== trim($command->reason) ? trim($command->reason) : $command->type->label();
        $this->stock->adjust($command->productId, $delta);
        try {
            return $this->inventory->addMovement(new NewStockMovement(
                $command->productId,
                $command->lotId,
                null,
                null,
                $command->occurredAt,
                $delta,
                $command->type,
                $reason,
                null,
                $command->actorId,
                $this->clock->now(),
            ));
        } catch (Throwable $exception) {
            $this->stock->adjust($command->productId, -$delta);

            throw $exception;
        }
    }
}
