<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardQuery;
use Timber\Timber;

final readonly class TaxDeclarationController
{
    public function __construct(private GetAnnualDashboardHandler $getDashboard)
    {
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }

        $requestedYear = isset($_GET['year']) ? absint($_GET['year']) : null;
        $dashboard = $this->getDashboard->handle(new GetAnnualDashboardQuery($requestedYear ?: null));
        $summary = $dashboard->summary;

        Timber::render('@luziapi_admin/pilotage/tax-declaration.twig', [
            'year'                 => $summary->year,
            'available_years'      => $dashboard->availableYears,
            'page_url'             => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'new_order_url'        => admin_url('admin.php?page=wc-orders&action=new'),
            'commercial_reference' => $this->formatMoney($summary->netOrderedTotal->cents()),
        ]);
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
