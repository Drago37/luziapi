<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Service;

use DateTimeImmutable;

final readonly class TrackingSession
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
