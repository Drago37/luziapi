<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount;

use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;

final readonly class ApplyThankYouDiscountCommand
{
    public function __construct(
        public int $orderId,
        public ThankYouDiscount $discount,
        public int $actorId,
    ) {
    }
}
