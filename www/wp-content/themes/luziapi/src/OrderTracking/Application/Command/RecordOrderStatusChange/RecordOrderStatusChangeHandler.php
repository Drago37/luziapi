<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\RecordOrderStatusChange;

use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Domain\StatusHistoryRepository;
use LuziApi\OrderTracking\Domain\StatusTransition;

final readonly class RecordOrderStatusChangeHandler
{
    public function __construct(
        private StatusHistoryRepository $history,
        private Clock $clock,
    ) {
    }

    public function handle(int $orderId, string $fromStatus, string $toStatus): void
    {
        $fromStatus = trim($fromStatus);
        $toStatus = trim($toStatus);
        if ($orderId <= 0 || '' === $toStatus || $fromStatus === $toStatus) {
            return;
        }

        $now = $this->clock->now();
        $this->history->record(new StatusTransition(
            $orderId,
            $fromStatus,
            $toStatus,
            $now,
            hash('sha256', implode('|', [$orderId, $fromStatus, $toStatus, $now->format('Y-m-d H:i:s.u')])),
        ));
    }
}
