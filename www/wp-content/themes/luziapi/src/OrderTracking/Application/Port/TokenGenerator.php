<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Port;

interface TokenGenerator
{
    public function generate(): string;
}
