<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Receipt;

use LuziApi\Shared\Domain\ValueObject\Money;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;

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
