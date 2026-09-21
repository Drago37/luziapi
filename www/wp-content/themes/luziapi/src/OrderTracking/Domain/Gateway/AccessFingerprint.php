<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain\Gateway;

interface AccessFingerprint
{
    public function token(string $rawToken): string;

    public function subject(string $value): string;
}
