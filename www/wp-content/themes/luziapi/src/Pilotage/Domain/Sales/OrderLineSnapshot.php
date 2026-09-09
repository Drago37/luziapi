<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class OrderLineSnapshot
{
    public function __construct(
        public int $productId,
        public string $name,
        public int $quantity,
        public Money $total,
    ) {
    }
}
