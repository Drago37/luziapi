<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\StartOrderAccess;

use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\OrderTrackingGateway;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use LuziApi\OrderTracking\Application\Service\TrackingSessionIssuer;
use LuziApi\OrderTracking\Domain\OrderAccessCredentials;

final readonly class StartOrderAccessHandler
{
    private const IP_LIMIT = 20;
    private const CREDENTIAL_LIMIT = 6;
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private OrderTrackingGateway $orders,
        private TrackingAccessRepository $access,
        private TrackingSessionIssuer $sessions,
        private AccessFingerprint $fingerprints,
        private Clock $clock,
    ) {
    }

    public function handle(OrderAccessCredentials $credentials, string $remoteAddress): StartOrderAccessResult
    {
        $now = $this->clock->now();
        $allowedByIp = $this->access->allowAttempt(
            'order_ip',
            $this->fingerprints->subject($remoteAddress),
            self::IP_LIMIT,
            self::WINDOW_SECONDS,
            $now,
        );
        $allowedByCredentials = $this->access->allowAttempt(
            'order_credentials',
            $this->fingerprints->subject($credentials->orderNumber . '|' . $credentials->email),
            self::CREDENTIAL_LIMIT,
            self::WINDOW_SECONDS,
            $now,
        );
        if (! $allowedByIp || ! $allowedByCredentials) {
            return StartOrderAccessResult::limited($credentials->orderNumber);
        }

        $orderId = $this->orders->findOrderId($credentials->orderNumber, $credentials->email);
        if (null === $orderId) {
            return StartOrderAccessResult::denied($credentials->orderNumber);
        }

        return StartOrderAccessResult::granted(
            $this->sessions->issue([$orderId], $now),
            $credentials->orderNumber,
        );
    }
}
