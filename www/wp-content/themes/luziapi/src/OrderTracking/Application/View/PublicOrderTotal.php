<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\View;

final readonly class PublicOrderTotal
{
    public function __construct(
        public string $label,
        public int $amountCents,
    ) {
    }
}
