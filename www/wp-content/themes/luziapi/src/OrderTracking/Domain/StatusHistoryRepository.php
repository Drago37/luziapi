<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain;

interface StatusHistoryRepository
{
    public function record(StatusTransition $transition): void;

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, list<StatusTransition>>
     */
    public function forOrderIds(array $orderIds): array;
}
