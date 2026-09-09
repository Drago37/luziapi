<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Inventory;

use DateTimeImmutable;

final readonly class NewHarvestLot
{
    public function __construct(
        public string $lotNumber,
        public int $productId,
        public DateTimeImmutable $harvestedAt,
        public DateTimeImmutable $jarredAt,
        public string $apiaryOrigin,
        public string $variety,
        public int $quantityJarred,
        public bool $stockAlreadyRecorded,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function harvestYear(): int
    {
        return (int) $this->harvestedAt->format('Y');
    }
}
