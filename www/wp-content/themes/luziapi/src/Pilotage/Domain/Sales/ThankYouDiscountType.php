<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

enum ThankYouDiscountType: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
