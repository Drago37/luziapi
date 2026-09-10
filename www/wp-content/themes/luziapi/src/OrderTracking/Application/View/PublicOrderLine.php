<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\View;

final readonly class PublicOrderLine
{
    public function __construct(
        public string $name,
        public int $quantity,
        public int $totalCents,
    ) {
    }
}
