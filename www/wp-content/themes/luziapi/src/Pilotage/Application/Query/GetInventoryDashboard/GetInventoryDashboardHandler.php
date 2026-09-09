<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetInventoryDashboard;

use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Product\ProductCatalog;

final readonly class GetInventoryDashboardHandler
{
    public function __construct(
        private ProductCatalog $products,
        private InventoryRepository $inventory,
    ) {
    }

    public function handle(): InventoryDashboardView
    {
        return new InventoryDashboardView(
            $this->products->all(),
            $this->inventory->lots(),
            $this->inventory->recentMovements(),
        );
    }
}
