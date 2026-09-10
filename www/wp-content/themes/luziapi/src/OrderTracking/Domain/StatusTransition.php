<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain;

use DateTimeImmutable;

final readonly class StatusTransition
{
    public function __construct(
        public int $orderId,
        public string $fromStatus,
        public string $toStatus,
        public DateTimeImmutable $occurredAt,
        public string $referenceKey,
    ) {
    }
}
