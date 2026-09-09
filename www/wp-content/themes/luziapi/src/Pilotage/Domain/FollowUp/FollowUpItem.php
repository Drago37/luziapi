<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\FollowUp;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;

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
