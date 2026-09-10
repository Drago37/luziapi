<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use LuziApi\OrderTracking\Application\Port\TokenGenerator;

final readonly class RandomTokenGenerator implements TokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
