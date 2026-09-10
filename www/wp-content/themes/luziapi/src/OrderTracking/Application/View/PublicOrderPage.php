<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\View;

final readonly class PublicOrderPage
{
    /** @param list<PublicOrderView> $orders */
    public function __construct(
        public array $orders,
        public int $currentPage,
        public int $totalPages,
        public int $totalOrders,
        public ?PublicOrderView $selectedOrder,
    ) {
    }
}
