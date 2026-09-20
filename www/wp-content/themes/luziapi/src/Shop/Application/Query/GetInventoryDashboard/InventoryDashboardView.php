<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetInventoryDashboard;

use LuziApi\Shop\Domain\Inventory\HarvestLot;
use LuziApi\Shop\Domain\Inventory\StockMovement;
use LuziApi\Shop\Domain\Product\ProductStockSnapshot;

final readonly class InventoryDashboardView
{
    /**
     * @param list<ProductStockSnapshot> $products
     * @param list<HarvestLot>           $lots
     * @param list<StockMovement>        $movements
     */
    public function __construct(
        public array $products,
        public array $lots,
        public array $movements,
    ) {
    }
}
