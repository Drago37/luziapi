<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Gateway;

interface StockLevelGateway
{
    public function adjust(int $productId, int $quantityDelta): void;
}
