<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\ApplyThankYouDiscount;

use LuziApi\Shop\Domain\Sales\ThankYouDiscount;

final readonly class ApplyThankYouDiscountCommand
{
    public function __construct(
        public int $orderId,
        public ThankYouDiscount $discount,
        public int $actorId,
    ) {
    }
}
