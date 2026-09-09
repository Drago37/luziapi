<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use LuziApi\Pilotage\Domain\Shared\Money;

final class AnnualSalesCalculator
{
    /**
     * @param list<OrderSnapshot> $orders
     */
    public function calculate(int $year, array $orders): AnnualSalesSummary
    {
        $orderedTotal = Money::zero();
        $refundedTotal = Money::zero();
        $ordersCount = 0;
        $itemsCount = 0;
        $monthlyTotals = array_fill(1, 12, 0);
        $statusCounts = [];
        $sourceTotals = [];
        $includedOrders = [];

        foreach ($orders as $order) {
            $statusCounts[$order->status] = ($statusCounts[$order->status] ?? 0) + 1;

            if (! OrderStatusPolicy::isCommerciallyValid($order->status)) {
                continue;
            }

            ++$ordersCount;
            $itemsCount += $order->itemsCount;
            $orderedTotal = $orderedTotal->add($order->total);
            $refundedTotal = $refundedTotal->add($order->refunded);
            $month = (int) $order->createdAt->format('n');
            $monthlyTotals[$month] += $order->total->cents();
            $source = '' !== $order->source ? $order->source : 'unknown';
            $sourceTotals[$source] = ($sourceTotals[$source] ?? 0) + $order->total->cents();
            $includedOrders[] = $order;
        }

        usort(
            $includedOrders,
            static fn (OrderSnapshot $left, OrderSnapshot $right): int => $right->createdAt <=> $left->createdAt,
        );

        $averageCents = $ordersCount > 0 ? (int) round($orderedTotal->cents() / $ordersCount) : 0;

        return new AnnualSalesSummary(
            $year,
            $orderedTotal,
            $refundedTotal,
            $orderedTotal->subtract($refundedTotal),
            $ordersCount,
            $itemsCount,
            new Money($averageCents),
            $monthlyTotals,
            $statusCounts,
            $sourceTotals,
            array_slice($includedOrders, 0, 8),
        );
    }
}
