<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Inventory;

use DateTimeImmutable;

final readonly class StockMovement
{
    public function __construct(
        public int $id,
        public int $sequenceNumber,
        public int $productId,
        public ?int $lotId,
        public ?int $orderId,
        public ?int $orderItemId,
        public DateTimeImmutable $occurredAt,
        public int $quantityDelta,
        public StockMovementType $type,
        public string $reason,
        public ?string $referenceKey,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
