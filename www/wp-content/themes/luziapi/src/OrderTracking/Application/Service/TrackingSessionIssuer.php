<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Service;

use DateTimeImmutable;
use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\TokenGenerator;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;

final readonly class TrackingSessionIssuer
{
    public const LIFETIME_SECONDS = 7200;

    public function __construct(
        private TrackingAccessRepository $access,
        private TokenGenerator $tokens,
        private AccessFingerprint $fingerprints,
    ) {
    }

    /** @param non-empty-list<int> $orderIds */
    public function issue(array $orderIds, DateTimeImmutable $at): TrackingSession
    {
        $token = $this->tokens->generate();
        $expiresAt = $at->modify('+' . self::LIFETIME_SECONDS . ' seconds');
        $this->access->createSession(
            $this->fingerprints->token($token),
            array_values(array_unique($orderIds)),
            $expiresAt,
            $at,
        );

        return new TrackingSession($token, $expiresAt);
    }
}
