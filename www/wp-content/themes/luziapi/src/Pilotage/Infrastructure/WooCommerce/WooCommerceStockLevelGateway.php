<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Port\StockLevelGateway;
use RuntimeException;

final class WooCommerceStockLevelGateway implements StockLevelGateway
{
    public function adjust(int $productId, int $quantityDelta): void
    {
        if (0 === $quantityDelta) {
            return;
        }

        $product = wc_get_product($productId);
        if (! $product || ! $product->managing_stock() || null === $product->get_stock_quantity()) {
            throw new RuntimeException('Product stock is not managed by WooCommerce.');
        }
        if ($product->get_stock_quantity() + $quantityDelta < 0) {
            throw new RuntimeException('Stock movement would create a negative stock.');
        }

        $updated = wc_update_product_stock(
            $product,
            abs($quantityDelta),
            $quantityDelta > 0 ? 'increase' : 'decrease',
        );
        if (is_wp_error($updated)) {
            throw new RuntimeException('WooCommerce stock could not be updated.');
        }
    }
}
