<?php

declare(strict_types=1);

namespace LuziApi\Shared\Domain;

use DateTimeImmutable;
use DateTimeZone;

interface Clock
{
    public function now(): DateTimeImmutable;

    public function timezone(): DateTimeZone;
}
