<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetProductDashboard;

use LuziApi\Shop\Domain\Product\ProductPerformance;

final readonly class ProductDashboardView
{
    /**
     * @param list<ProductPerformance> $products
     * @param list<int>                $availableYears
     */
    public function __construct(
        public int $year,
        public array $products,
        public array $availableYears,
    ) {
    }
}
