<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress\Admin;

use LuziApi\Shared\Infrastructure\Wp;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\LoyaltyCustomerRow;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\LoyaltyRewardHolder;
use Timber\Timber;

final readonly class LoyaltyController
{
    public function __construct(private GetLoyaltyDashboardHandler $getDashboard)
    {
    }

    public function render(): void
    {
        $this->assertPermission();
        $requestedPeriod = isset($_GET['period']) ? sanitize_key(wp_unslash(Wp::str($_GET['period']))) : null;
        $dashboard = $this->getDashboard->handle(new GetLoyaltyDashboardQuery('' !== (string) $requestedPeriod ? $requestedPeriod : null));

        Timber::render('@luziapi_admin/pilotage/loyalty.twig', [

            'pilotage_tabs' => PilotageTabs::links('loyalty'),
            'page_url'            => $this->pageUrl($dashboard->periodKey),
            'dashboard_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'          => admin_url('admin.php?page=wc-orders'),
            'period_label'        => $dashboard->periodLabel,
            'periods'             => $this->periods($dashboard),
            'grand_total'         => [
                ['label' => 'Clients fidélité', 'value' => (string) $dashboard->grandCustomers],
                ['label' => 'Pots achetés', 'value' => (string) $dashboard->grandPots],
                ['label' => 'Pots offerts', 'value' => (string) $dashboard->grandOfferedPots],
                ['label' => 'Remises remerciement', 'value' => $this->formatMoney($dashboard->grandDiscountCents)],
                ['label' => 'Avantages dus (passif)', 'value' => (string) $dashboard->grandRewardsOwed],
            ],
            'metrics'             => [
                ['label' => 'Clients fidélité', 'value' => (string) $dashboard->totalCustomers],
                ['label' => 'Pots achetés', 'value' => (string) $dashboard->totalPots],
                ['label' => 'Pots offerts', 'value' => (string) $dashboard->totalOfferedPots],
                ['label' => 'Remises remerciement', 'value' => $this->formatMoney($dashboard->totalDiscountCents)],
            ],
            'rewards_outstanding' => array_map($this->formatRewardHolder(...), $dashboard->rewardsOutstanding),
            'top_buyers'          => array_map($this->formatRow(...), $dashboard->topBuyers),
            'top_benefited'       => array_map($this->formatRow(...), $dashboard->topBenefited),
            'top_discounts'       => array_map($this->formatRow(...), $dashboard->topDiscounts),
            'customers'           => array_map($this->formatRow(...), $dashboard->customers),
        ]);
    }

    /**
     * Options du sélecteur de période : chaque année, puis le total toutes années.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    private function periods(\LuziApi\Shop\Application\Query\GetLoyaltyDashboard\LoyaltyDashboardView $dashboard): array
    {
        $options = [];
        foreach ($dashboard->availableYears as $year) {
            $options[] = ['key' => (string) $year, 'label' => (string) $year];
        }
        $options[] = ['key' => 'all', 'label' => 'Total (toutes années)'];

        return array_map(
            fn (array $option): array => [
                'key'    => $option['key'],
                'label'  => $option['label'],
                'url'    => $this->pageUrl($option['key']),
                'active' => $option['key'] === $dashboard->periodKey,
            ],
            $options,
        );
    }

    private function pageUrl(string $period): string
    {
        return admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty&period=' . $period);
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

    /**
     * @return array{name: string, rewards: string, details_url: string}
     */
    private function formatRewardHolder(LoyaltyRewardHolder $holder): array
    {
        return [
            'name'        => $holder->name,
            'rewards'     => (string) $holder->rewards,
            'details_url' => add_query_arg(
                'customer',
                $holder->customerId,
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
