<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Product;

use LuziApi\Shop\Domain\Shared\Money;

final readonly class ProductStockSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sku,
        public ?int $stockQuantity,
        public int $lowStockThreshold,
        public bool $purchasable,
        public Money $price,
    ) {
    }
}
