<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetProductDashboard;

use LuziApi\Pilotage\Domain\Product\ProductPerformance;

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
