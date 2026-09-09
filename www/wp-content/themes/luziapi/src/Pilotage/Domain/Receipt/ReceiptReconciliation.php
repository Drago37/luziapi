<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class ReceiptReconciliation
{
    public function __construct(
        public OrderSnapshot $order,
        public Money $expected,
        public Money $recorded,
        public Money $difference,
    ) {
    }
}
