<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\LoyaltyCustomerRow;
use Timber\Timber;

final readonly class LoyaltyController
{
    public function __construct(private GetLoyaltyDashboardHandler $getDashboard)
    {
    }

    public function render(): void
    {
        $this->assertPermission();
        $requestedYear = isset($_GET['year']) ? absint($_GET['year']) : null;
        $dashboard = $this->getDashboard->handle(new GetLoyaltyDashboardQuery($requestedYear ?: null));

        Timber::render('@luziapi_admin/pilotage/loyalty.twig', [
            'page_url'            => $this->pageUrl($dashboard->year),
            'dashboard_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'          => admin_url('admin.php?page=wc-orders'),
            'year'                => $dashboard->year,
            'available_years'     => array_map(
                fn (int $year): array => ['year' => $year, 'url' => $this->pageUrl($year)],
                $dashboard->availableYears,
            ),
            'metrics'             => [
                ['label' => 'Clients fidélité', 'value' => (string) $dashboard->totalCustomers],
                ['label' => 'Pots achetés', 'value' => (string) $dashboard->totalPots],
                ['label' => 'Pots offerts', 'value' => (string) $dashboard->totalOfferedPots],
                ['label' => 'Remises remerciement', 'value' => $this->formatMoney($dashboard->totalDiscountCents)],
            ],
            'top_buyers'          => array_map($this->formatRow(...), $dashboard->topBuyers),
            'top_benefited'       => array_map($this->formatRow(...), $dashboard->topBenefited),
            'top_discounts'       => array_map($this->formatRow(...), $dashboard->topDiscounts),
            'customers'           => array_map($this->formatRow(...), $dashboard->customers),
        ]);
    }

    private function pageUrl(int $year): string
    {
        return admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty&year=' . $year);
    }

    /**
     * @return array<string, string>
     */
    private function formatRow(LoyaltyCustomerRow $row): array
    {
        return [
            'name'         => $row->name,
            'city'         => $row->city,
            'pots_bought'  => (string) $row->potsBought,
            'offered_pots' => (string) $row->offeredPots,
            'discount'     => $this->formatMoney($row->discountCents),
            'details_url'  => add_query_arg(
                'customer',
                $row->customerId,
                admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            ),
        ];
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }
}
