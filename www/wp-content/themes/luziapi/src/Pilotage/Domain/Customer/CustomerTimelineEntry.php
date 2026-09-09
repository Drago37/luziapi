<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

use DateTimeImmutable;

final readonly class CustomerTimelineEntry
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public DateTimeImmutable $occurredAt,
        public string $content,
        public bool $public,
        public string $author,
    ) {
    }
}
