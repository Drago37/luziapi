<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Product;

interface ProductCatalog
{
    /** @return list<ProductStockSnapshot> */
    public function all(): array;
}
