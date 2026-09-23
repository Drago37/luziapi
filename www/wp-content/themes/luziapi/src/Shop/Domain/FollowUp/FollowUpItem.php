<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\FollowUp;

use LuziApi\Shared\Domain\ValueObject\Money;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;

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
