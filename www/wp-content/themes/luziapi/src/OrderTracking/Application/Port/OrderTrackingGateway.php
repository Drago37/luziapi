<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Port;

use LuziApi\OrderTracking\Application\View\PublicOrderPage;

interface OrderTrackingGateway
{
    public function findOrderId(string $orderNumber, string $email): ?int;

    /** @return list<int> */
    public function findOrderIdsByEmail(string $email): array;

    /** @param non-empty-list<int> $allowedOrderIds */
    public function getOrders(array $allowedOrderIds, int $page, int $perPage, string $selectedOrderNumber): PublicOrderPage;
}
