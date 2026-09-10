<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Port;

interface MagicLinkUrlGenerator
{
    public function forToken(string $token): string;
}
