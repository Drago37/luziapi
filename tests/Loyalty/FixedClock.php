<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Loyalty\Application\Port\Clock;

final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public static function at(string $dateTime): self
    {
        return new self(new DateTimeImmutable($dateTime, new DateTimeZone('Europe/Paris')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
