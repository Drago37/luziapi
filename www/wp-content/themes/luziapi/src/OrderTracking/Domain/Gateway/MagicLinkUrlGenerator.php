<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain\Gateway;

interface MagicLinkUrlGenerator
{
    public function forToken(string $token): string;
}
