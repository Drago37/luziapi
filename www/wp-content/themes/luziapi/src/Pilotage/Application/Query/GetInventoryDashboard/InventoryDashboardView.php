<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetInventoryDashboard;

use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\StockMovement;
use LuziApi\Pilotage\Domain\Product\ProductStockSnapshot;

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
