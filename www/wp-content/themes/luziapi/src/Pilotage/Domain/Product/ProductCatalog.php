<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Product;

interface ProductCatalog
{
    /** @return list<ProductStockSnapshot> */
    public function all(): array;
}
