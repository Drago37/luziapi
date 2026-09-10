<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\RevokeTrackingSession;

use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;

final readonly class RevokeTrackingSessionHandler
{
    public function __construct(
        private TrackingAccessRepository $access,
        private AccessFingerprint $fingerprints,
    ) {
    }

    public function handle(string $sessionToken): void
    {
        if (1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionToken)) {
            $this->access->revokeSession($this->fingerprints->token($sessionToken));
        }
    }
}
