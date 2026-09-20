<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Customer;

interface CustomerTimelineRepository
{
    /**
     * @param list<int> $orderIds
     *
     * @return list<CustomerTimelineEntry>
     */
    public function forOrderIds(array $orderIds): array;
}
