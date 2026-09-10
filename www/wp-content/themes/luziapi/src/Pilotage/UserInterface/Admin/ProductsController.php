<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Query\GetProductDashboard\GetProductDashboardHandler;
use LuziApi\Pilotage\Domain\Product\ProductPerformance;
use Timber\Timber;

final readonly class ProductsController
{
    public function __construct(private GetProductDashboardHandler $getProducts)
    {
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }

        $requestedYear = isset($_GET['year']) ? absint($_GET['year']) : null;
        $dashboard = $this->getProducts->handle($requestedYear ?: null);
        $sold = array_sum(array_map(static fn (ProductPerformance $product): int => $product->soldQuantity, $dashboard->products));
        $revenue = array_sum(array_map(static fn (ProductPerformance $product): int => $product->revenue->cents(), $dashboard->products));
        $lowStock = count(array_filter($dashboard->products, static fn (ProductPerformance $product): bool => $product->lowStock));

        Timber::render('@luziapi_admin/pilotage/products.twig', [
            'year'                 => $dashboard->year,
            'available_years'      => $dashboard->availableYears,
            'page_url'             => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url'  => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'inventory_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'loyalty_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'products_admin_url'   => admin_url('edit.php?post_type=product'),
            'metrics'              => [
                ['label' => 'Pots vendus', 'value' => (string) $sold],
                ['label' => 'CA produits', 'value' => $this->formatMoney($revenue)],
                ['label' => 'Variétés suivies', 'value' => (string) count($dashboard->products)],
                ['label' => 'Stocks faibles', 'value' => (string) $lowStock],
            ],
            'products'             => array_map($this->formatProduct(...), $dashboard->products),
            'chart_labels'         => array_map(static fn (ProductPerformance $product): string => $product->name, $dashboard->products),
            'chart_values'         => array_map(static fn (ProductPerformance $product): int => $product->soldQuantity, $dashboard->products),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatProduct(ProductPerformance $product): array
    {
        return [
            'name'        => $product->name,
            'sku'         => $product->sku,
            'sold'        => $product->soldQuantity,
            'revenue'     => $this->formatMoney($product->revenue->cents()),
            'stock'       => $product->stockQuantity,
            'low_stock'   => $product->lowStock,
            'edit_url'    => get_edit_post_link($product->id, 'raw') ?: '',
        ];
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
