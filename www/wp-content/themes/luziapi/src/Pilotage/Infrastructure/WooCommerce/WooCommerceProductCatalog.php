<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Domain\Product\ProductCatalog;
use LuziApi\Pilotage\Domain\Product\ProductStockSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use WC_Product;

final class WooCommerceProductCatalog implements ProductCatalog
{
    public function all(): array
    {
        $products = wc_get_products([
            'limit'   => -1,
            'orderby' => 'name',
            'order'   => 'ASC',
            'status'  => ['publish', 'private'],
        ]);
        $defaultLowStock = (int) get_option('woocommerce_notify_low_stock_amount', 5);
        $snapshots = [];

        foreach ($products as $product) {
            if (! $product instanceof WC_Product || $product->is_type('variation')) {
                continue;
            }
            $threshold = $product->get_low_stock_amount();
            $snapshots[] = new ProductStockSnapshot(
                $product->get_id(),
                $product->get_name(),
                $product->get_sku(),
                $product->managing_stock() ? $product->get_stock_quantity() : null,
                is_numeric($threshold) ? (int) $threshold : $defaultLowStock,
                $product->is_purchasable(),
                new Money((int) round((float) wc_get_price_to_display($product) * 100)),
            );
        }

        return $snapshots;
    }
}
