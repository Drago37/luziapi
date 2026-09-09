<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

interface StockLevelGateway
{
    public function adjust(int $productId, int $quantityDelta): void;
}
