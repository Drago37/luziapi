<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Gateway;

use LuziApi\Shop\Application\Command\CreateQuickSale\CreatedQuickSale;
use LuziApi\Shop\Application\Command\CreateQuickSale\CreateQuickSaleCommand;

interface QuickSaleOrderWriter
{
    public function create(CreateQuickSaleCommand $command): CreatedQuickSale;

    public function markReceiptRecorded(int $orderId): void;
}
