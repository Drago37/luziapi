<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Sales;

use LuziApi\Shop\Domain\Shared\Money;

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
