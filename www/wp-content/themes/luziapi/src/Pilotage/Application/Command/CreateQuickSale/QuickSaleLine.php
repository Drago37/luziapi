<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

final readonly class QuickSaleLine
{
    public function __construct(public int $productId, public int $quantity)
    {
    }
}
