<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

final readonly class CreatedQuickSale
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public int $totalCents,
        public bool $receiptRecorded,
        public bool $alreadyExisted = false,
    ) {
    }
}
