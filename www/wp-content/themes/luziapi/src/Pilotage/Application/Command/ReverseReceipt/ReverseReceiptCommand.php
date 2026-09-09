<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ReverseReceipt;

final readonly class ReverseReceiptCommand
{
    public function __construct(
        public int $entryId,
        public string $reason,
        public int $actorId,
    ) {
    }
}
