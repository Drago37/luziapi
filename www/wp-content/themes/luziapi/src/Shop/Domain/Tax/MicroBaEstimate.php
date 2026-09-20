<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Tax;

use LuziApi\Shop\Domain\Shared\Money;

final readonly class MicroBaEstimate
{
    /** @param array<int, Money> $annualReceipts */
    public function __construct(
        public int $declarationYear,
        public array $annualReceipts,
        public int $yearsCount,
        public Money $averageReceipts,
        public Money $allowance,
        public Money $taxableProfit,
    ) {
    }
}
