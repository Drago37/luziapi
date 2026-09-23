<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Receipt;

use LuziApi\Shared\Domain\ValueObject\Money;

final readonly class AnnualReceiptSummary
{
    /**
     * @param array<int, int>    $monthlyNetCents
     * @param array<string, int> $paymentMethodNetCents
     * @param list<ReceiptEntry> $entries
     */
    public function __construct(
        public int $year,
        public Money $collected,
        public Money $refunded,
        public Money $net,
        public array $monthlyNetCents,
        public array $paymentMethodNetCents,
        public array $entries,
    ) {
    }
}
