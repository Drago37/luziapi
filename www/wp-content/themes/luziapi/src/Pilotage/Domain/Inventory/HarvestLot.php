<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Inventory;

use DateTimeImmutable;

final readonly class HarvestLot
{
    public function __construct(
        public int $id,
        public string $lotNumber,
        public int $productId,
        public int $harvestYear,
        public DateTimeImmutable $harvestedAt,
        public DateTimeImmutable $jarredAt,
        public string $apiaryOrigin,
        public string $variety,
        public int $quantityJarred,
        public int $stockRemaining,
        public bool $stockAlreadyRecorded,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
