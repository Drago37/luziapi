<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\View;

use DateTimeImmutable;

final readonly class PublicOrderView
{
    /**
     * @param list<PublicOrderLine>   $lines
     * @param list<PublicOrderTotal>  $totals
     * @param list<PublicOrderUpdate> $updates
     */
    public function __construct(
        public int $id,
        public string $number,
        public string $firstName,
        public DateTimeImmutable $createdAt,
        public string $status,
        public int $totalCents,
        public string $currency,
        public string $paymentMethod,
        public string $fulfillmentMode,
        public string $fulfillmentLabel,
        public string $customerNote,
        public array $lines,
        public array $totals,
        public array $updates,
    ) {
    }
}
