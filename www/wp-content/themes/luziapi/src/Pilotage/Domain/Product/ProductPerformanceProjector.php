<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Product;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Sales\OrderStatusPolicy;
use LuziApi\Pilotage\Domain\Shared\Money;

final class ProductPerformanceProjector
{
    /**
     * @param list<ProductStockSnapshot> $products
     * @param list<OrderSnapshot>        $orders
     *
     * @return list<ProductPerformance>
     */
    public function project(array $products, array $orders): array
    {
        $sales = [];
        foreach ($orders as $order) {
            if (! OrderStatusPolicy::isCommerciallyValid($order->status)) {
                continue;
            }
            foreach ($order->lines as $line) {
                $sales[$line->productId]['quantity'] = ($sales[$line->productId]['quantity'] ?? 0) + $line->quantity;
                $sales[$line->productId]['revenue'] = ($sales[$line->productId]['revenue'] ?? 0) + $line->total->cents();
            }
        }

        $performances = [];
        foreach ($products as $product) {
            $stock = $product->stockQuantity;
            $performances[] = new ProductPerformance(
                $product->id,
                $product->name,
                $product->sku,
                (int) ($sales[$product->id]['quantity'] ?? 0),
                new Money((int) ($sales[$product->id]['revenue'] ?? 0)),
                $stock,
                $product->lowStockThreshold,
                null !== $stock && $stock <= $product->lowStockThreshold,
            );
        }

        usort($performances, static fn (ProductPerformance $left, ProductPerformance $right): int => $right->soldQuantity <=> $left->soldQuantity);

        return $performances;
    }
}
