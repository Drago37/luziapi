<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain\Gateway;

interface TokenGenerator
{
    public function generate(): string;
}
