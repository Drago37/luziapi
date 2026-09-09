<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

use DateTimeImmutable;
use DateTimeZone;

interface Clock
{
    public function now(): DateTimeImmutable;

    public function timezone(): DateTimeZone;
}
