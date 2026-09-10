<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\RequestHistoryLink;

final readonly class HistoryLinkRequestResult
{
    public function __construct(
        public bool $messageSent,
        public bool $rateLimited,
    ) {
    }
}
