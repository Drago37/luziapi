<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Port\Clock;

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
