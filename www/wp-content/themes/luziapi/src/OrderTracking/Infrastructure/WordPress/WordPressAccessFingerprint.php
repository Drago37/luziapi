<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use LuziApi\OrderTracking\Application\Port\AccessFingerprint;

final readonly class WordPressAccessFingerprint implements AccessFingerprint
{
    public function __construct(private string $secret)
    {
    }

    public function token(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function subject(string $value): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($value)), $this->secret);
    }
}
