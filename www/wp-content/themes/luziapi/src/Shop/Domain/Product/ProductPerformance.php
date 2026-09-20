<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Product;

use LuziApi\Shared\Domain\ValueObject\Money;

final readonly class ProductPerformance
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sku,
        public int $soldQuantity,
        public Money $revenue,
        public ?int $stockQuantity,
        public int $lowStockThreshold,
        public bool $lowStock,
    ) {
    }
}
