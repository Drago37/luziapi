<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class OrderSnapshot
{
    public function __construct(
        public int $id,
        public string $number,
        public DateTimeImmutable $createdAt,
        public string $status,
        public Money $total,
        public Money $refunded,
        public int $itemsCount,
        public string $customerName,
        public string $email,
        public string $phone,
        public string $city,
        public string $source,
        public string $fulfillment,
    ) {
    }
}
