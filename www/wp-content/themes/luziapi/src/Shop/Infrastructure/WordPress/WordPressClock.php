<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shop\Application\Port\Clock;

final class WordPressClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return wp_timezone();
    }
}
