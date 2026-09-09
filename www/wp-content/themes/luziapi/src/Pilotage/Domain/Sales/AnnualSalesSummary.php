<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class AnnualSalesSummary
{
    /**
     * @param array<int, int>    $monthlyTotalsCents
     * @param array<string, int> $statusCounts
     * @param array<string, int> $sourceTotalsCents
     * @param list<OrderSnapshot> $recentOrders
     */
    public function __construct(
        public int $year,
        public Money $orderedTotal,
        public Money $refundedTotal,
        public Money $netOrderedTotal,
        public int $ordersCount,
        public int $itemsCount,
        public Money $averageOrder,
        public array $monthlyTotalsCents,
        public array $statusCounts,
        public array $sourceTotalsCents,
        public array $recentOrders,
    ) {
    }
}
