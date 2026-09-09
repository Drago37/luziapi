<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Port\TaxSettings;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardQuery;
use LuziApi\Pilotage\Application\Query\GetTaxDeclaration\GetTaxDeclarationHandler;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Shared\Money;
use Timber\Timber;

final readonly class TaxDeclarationController
{
    public function __construct(
        private GetTaxDeclarationHandler $getTaxDeclaration,
        private GetAnnualDashboardHandler $getDashboard,
        private TaxSettings $settings,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_save_tax_settings', [$this, 'saveSettings']);
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }

        $requestedYear = isset($_GET['year']) ? absint($_GET['year']) : null;
        $taxDeclaration = $this->getTaxDeclaration->handle($requestedYear ?: null);
        $estimate = $taxDeclaration->estimate;
        $dashboard = $this->getDashboard->handle(new GetAnnualDashboardQuery($estimate->declarationYear));
        $annualReceipts = [];
        foreach ($estimate->annualReceipts as $year => $amount) {
            $annualReceipts[] = ['year' => $year, 'amount' => $this->formatMoney($amount->cents())];
        }

        Timber::render('@luziapi_admin/pilotage/tax-declaration.twig', [
            'year'                 => $estimate->declarationYear,
            'available_years'      => $taxDeclaration->availableYears,
            'activity_start_year'  => $taxDeclaration->activityStartYear,
            'page_url'             => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'new_order_url'        => admin_url('admin.php?page=wc-orders&action=new'),
            'settings_action'      => admin_url('admin-post.php'),
            'settings_nonce'       => wp_create_nonce('luziapi_save_tax_settings'),
            'annual_receipts'      => $annualReceipts,
            'declared_receipts'    => $this->formatMoney(($estimate->annualReceipts[$estimate->declarationYear] ?? Money::zero())->cents()),
            'average_receipts'     => $this->formatMoney($estimate->averageReceipts->cents()),
            'allowance'            => $this->formatMoney($estimate->allowance->cents()),
            'taxable_profit'       => $this->formatMoney($estimate->taxableProfit->cents()),
            'years_count'          => $estimate->yearsCount,
            'commercial_reference' => $this->formatMoney($dashboard->summary->netOrderedTotal->cents()),
            'notice'               => isset($_GET['tax_notice']) ? sanitize_key(wp_unslash((string) $_GET['tax_notice'])) : '',
        ]);
    }

    public function saveSettings(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
        check_admin_referer('luziapi_save_tax_settings');
        $currentYear = (int) wp_date('Y');
        $year = max(2000, min(absint($_POST['activity_start_year'] ?? 0), $currentYear));
        $this->settings->saveActivityStartYear($year);
        $this->activity->record(
            ActivityCategory::Settings,
            'activity_start_year_changed',
            'tax_settings',
            null,
            'Année de début d’activité fiscal modifiée',
            ['Nouvelle valeur' => (string) $year],
            get_current_user_id(),
        );

        wp_safe_redirect(add_query_arg(
            'tax_notice',
            'saved',
            admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
        ));
        exit;
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
