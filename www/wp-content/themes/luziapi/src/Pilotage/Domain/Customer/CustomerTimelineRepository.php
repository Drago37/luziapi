<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

interface CustomerTimelineRepository
{
    /**
     * @param list<int> $orderIds
     *
     * @return list<CustomerTimelineEntry>
     */
    public function forOrderIds(array $orderIds): array;
}
