<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardQuery;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use Timber\Timber;

final readonly class DashboardController
{
    private const MONTHS = [
        1 => 'Jan.',
        2 => 'Fév.',
        3 => 'Mars',
        4 => 'Avr.',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juil.',
        8 => 'Août',
        9 => 'Sept.',
        10 => 'Oct.',
        11 => 'Nov.',
        12 => 'Déc.',
    ];

    private const STATUS_LABELS = [
        'pending'          => 'En attente de paiement',
        'on-hold'          => 'Règlement à vérifier',
        'processing'       => 'À préparer',
        'out-for-delivery' => 'En cours de livraison',
        'ready-for-pickup' => 'Prête au retrait',
        'completed'        => 'Terminée',
        'cancelled'        => 'Annulée',
        'refunded'         => 'Remboursée',
        'failed'           => 'Échouée',
    ];

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
        $sourceLabels = function_exists('luziapi_order_source_options')
            ? luziapi_order_source_options()
            : [];
        $sourceLabels['unknown'] = 'Non renseignée';
        $sources = [];

        foreach ($summary->sourceTotalsCents as $source => $total) {
            $sources[] = [
                'label' => $sourceLabels[$source] ?? $source,
                'total' => $this->formatMoney($total),
            ];
        }

        usort($sources, static fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        Timber::render('@luziapi_admin/pilotage/dashboard.twig', [
            'year'              => $summary->year,
            'available_years'   => $dashboard->availableYears,
            'page_url'          => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'orders_url'        => admin_url('admin.php?page=wc-orders'),
            'new_order_url'     => admin_url('admin.php?page=wc-orders&action=new'),
            'metrics'           => [
                ['label' => 'Commandes validées', 'value' => $this->formatMoney($summary->orderedTotal->cents())],
                ['label' => 'Après remboursements', 'value' => $this->formatMoney($summary->netOrderedTotal->cents())],
                ['label' => 'Commandes', 'value' => (string) $summary->ordersCount],
                ['label' => 'Pots vendus', 'value' => (string) $summary->itemsCount],
                ['label' => 'Panier moyen', 'value' => $this->formatMoney($summary->averageOrder->cents())],
            ],
            'monthly_labels'    => array_values(self::MONTHS),
            'monthly_values'    => array_map(
                static fn (int $cents): float => $cents / 100,
                array_values($summary->monthlyTotalsCents),
            ),
            'status_cards'      => $this->statusCards($summary->statusCounts),
            'sources'           => $sources,
            'recent_orders'     => array_map($this->formatOrder(...), $summary->recentOrders),
            'has_orders'        => $summary->ordersCount > 0,
        ]);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{label: string, count: int}>
     */
    private function statusCards(array $counts): array
    {
        $cards = [];
        foreach (['on-hold', 'processing', 'out-for-delivery', 'ready-for-pickup'] as $status) {
            $cards[] = [
                'label' => self::STATUS_LABELS[$status],
                'count' => $counts[$status] ?? 0,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, string>
     */
    private function formatOrder(OrderSnapshot $order): array
    {
        return [
            'number'   => $order->number,
            'url'      => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->id),
            'date'     => wp_date('d/m/Y à H:i', $order->createdAt->getTimestamp()),
            'customer' => $order->customerName,
            'total'    => $this->formatMoney($order->total->cents()),
            'status'   => self::STATUS_LABELS[$order->status] ?? $order->status,
        ];
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
