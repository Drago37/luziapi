<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Port;

use LuziApi\OrderTracking\Application\Service\TrackingSession;

interface TrackingSessionCookie
{
    public function read(): string;

    public function write(TrackingSession $session): void;

    public function clear(): void;
}
