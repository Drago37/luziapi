<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\StartOrderAccess;

use LuziApi\OrderTracking\Application\Service\TrackingSession;

final readonly class StartOrderAccessResult
{
    private function __construct(
        public string $status,
        public ?TrackingSession $session,
        public string $orderNumber,
    ) {
    }

    public static function granted(TrackingSession $session, string $orderNumber): self
    {
        return new self('granted', $session, $orderNumber);
    }

    public static function denied(string $orderNumber): self
    {
        return new self('denied', null, $orderNumber);
    }

    public static function limited(string $orderNumber): self
    {
        return new self('limited', null, $orderNumber);
    }
}
