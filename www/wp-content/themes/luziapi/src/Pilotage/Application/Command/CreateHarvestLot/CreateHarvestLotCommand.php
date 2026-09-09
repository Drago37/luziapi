<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateHarvestLot;

use DateTimeImmutable;

final readonly class CreateHarvestLotCommand
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
        public int $actorId,
    ) {
    }
}
