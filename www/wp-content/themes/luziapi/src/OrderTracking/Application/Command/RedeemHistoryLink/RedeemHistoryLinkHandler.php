<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\RedeemHistoryLink;

use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use LuziApi\OrderTracking\Application\Service\TrackingSession;
use LuziApi\OrderTracking\Application\Service\TrackingSessionIssuer;

final readonly class RedeemHistoryLinkHandler
{
    public function __construct(
        private TrackingAccessRepository $access,
        private TrackingSessionIssuer $sessions,
        private AccessFingerprint $fingerprints,
        private Clock $clock,
    ) {
    }

    public function handle(string $token): ?TrackingSession
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }

        $now = $this->clock->now();
        $orderIds = $this->access->consumeMagicLink($this->fingerprints->token($token), $now);
        if (null === $orderIds) {
            return null;
        }

        return $this->sessions->issue($orderIds, $now);
    }
}
