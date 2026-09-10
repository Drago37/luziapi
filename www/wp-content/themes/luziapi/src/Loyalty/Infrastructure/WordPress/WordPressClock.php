<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\Loyalty\Application\Port\Clock;

final readonly class WordPressClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', wp_timezone());
    }
}
