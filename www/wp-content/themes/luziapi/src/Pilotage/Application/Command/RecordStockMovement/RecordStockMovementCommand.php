<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordStockMovement;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Inventory\StockMovementType;

final readonly class RecordStockMovementCommand
{
    public function __construct(
        public int $productId,
        public ?int $lotId,
        public StockMovementType $type,
        public int $quantity,
        public string $reason,
        public DateTimeImmutable $occurredAt,
        public int $actorId,
    ) {
    }
}
