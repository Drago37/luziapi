<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreatedQuickSale;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;

interface QuickSaleOrderWriter
{
    public function create(CreateQuickSaleCommand $command): CreatedQuickSale;

    public function markReceiptRecorded(int $orderId): void;
}
