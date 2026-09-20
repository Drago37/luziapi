<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Port;

interface StockLevelGateway
{
    public function adjust(int $productId, int $quantityDelta): void;
}
