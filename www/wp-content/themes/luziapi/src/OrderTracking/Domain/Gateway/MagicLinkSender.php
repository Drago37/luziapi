<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain\Gateway;

use DateTimeImmutable;

interface MagicLinkSender
{
    public function send(string $email, string $accessUrl, DateTimeImmutable $expiresAt): bool;
}
