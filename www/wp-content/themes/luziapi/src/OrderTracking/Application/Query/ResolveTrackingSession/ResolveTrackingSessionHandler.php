<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Query\ResolveTrackingSession;

use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\OrderTrackingGateway;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use LuziApi\OrderTracking\Application\View\PublicOrderPage;

final readonly class ResolveTrackingSessionHandler
{
    public function __construct(
        private TrackingAccessRepository $access,
        private OrderTrackingGateway $orders,
        private AccessFingerprint $fingerprints,
        private Clock $clock,
    ) {
    }

    public function handle(string $sessionToken, int $page, int $perPage, string $selectedOrderNumber): ?PublicOrderPage
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionToken)) {
            return null;
        }

        $orderIds = $this->access->sessionOrderIds(
            $this->fingerprints->token($sessionToken),
            $this->clock->now(),
        );
        if (null === $orderIds) {
            return null;
        }

        return $this->orders->getOrders($orderIds, $page, $perPage, $selectedOrderNumber);
    }
}
