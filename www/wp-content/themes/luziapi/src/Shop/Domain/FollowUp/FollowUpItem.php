<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\FollowUp;

use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shop\Domain\Shared\Money;

final readonly class FollowUpItem
{
    public function __construct(
        public OrderSnapshot $order,
        public string $reason,
        public int $ageDays,
        public Money $outstanding,
    ) {
    }
}
