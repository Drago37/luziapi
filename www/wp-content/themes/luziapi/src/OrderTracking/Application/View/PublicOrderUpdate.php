<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\View;

use DateTimeImmutable;

final readonly class PublicOrderUpdate
{
    public function __construct(
        public string $type,
        public string $title,
        public string $content,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
