<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Sales\OrderStatusPolicy;
use LuziApi\Pilotage\Domain\Shared\Money;

final class ReceiptReconciliationProjector
{
    /**
     * @param list<OrderSnapshot> $orders
     * @param array<int, int>     $receiptTotalsByOrder
     *
     * @return list<ReceiptReconciliation>
     */
    public function project(array $orders, array $receiptTotalsByOrder): array
    {
        $reconciliations = [];

        foreach ($orders as $order) {
            if (! OrderStatusPolicy::isCommerciallyValid($order->status)) {
                continue;
            }

            $expected = $order->total->subtract($order->refunded);
            $recorded = new Money($receiptTotalsByOrder[$order->id] ?? 0);
            $difference = $expected->subtract($recorded);
            if (0 === $difference->cents()) {
                continue;
            }

            $reconciliations[] = new ReceiptReconciliation($order, $expected, $recorded, $difference);
        }

        usort(
            $reconciliations,
            static fn (ReceiptReconciliation $left, ReceiptReconciliation $right): int => $right->order->createdAt <=> $left->order->createdAt,
        );

        return $reconciliations;
    }
}
