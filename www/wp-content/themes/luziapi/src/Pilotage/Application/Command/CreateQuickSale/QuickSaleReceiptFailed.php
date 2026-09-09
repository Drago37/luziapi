<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

use RuntimeException;
use Throwable;

final class QuickSaleReceiptFailed extends RuntimeException
{
    public function __construct(public readonly CreatedQuickSale $sale, Throwable $previous)
    {
        parent::__construct('Quick sale order was created but its receipt could not be recorded.', 0, $previous);
    }
}
