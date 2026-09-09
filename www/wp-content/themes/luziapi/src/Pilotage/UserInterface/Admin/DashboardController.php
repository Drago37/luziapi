<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Query\GetActivityLog\GetActivityLogHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardQuery;
use LuziApi\Pilotage\Domain\Activity\ActivityEntry;
use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use LuziApi\Pilotage\Domain\FollowUp\FollowUpItem;
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

    public function __construct(
        private GetAnnualDashboardHandler $getDashboard,
        private GetActivityLogHandler $getActivity,
        private Clock $clock,
    ) {
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
        $now = $this->clock->now();
        $recentActivity = $this->getActivity->handle(new ActivityFilter(
            $now->modify('-7 days'),
            $now,
            null,
            '',
            6,
        ));

        Timber::render('@luziapi_admin/pilotage/dashboard.twig', [
            'year'              => $summary->year,
            'available_years'   => $dashboard->availableYears,
            'page_url'          => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'receipts_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'products_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'    => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'        => admin_url('admin.php?page=wc-orders'),
            'new_order_url'     => admin_url('admin.php?page=wc-orders&action=new'),
            'metrics'           => [
                ['label' => 'Recettes encaissées', 'value' => $this->formatMoney($dashboard->receipts->net->cents())],
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
            'follow_up_count'   => $dashboard->followUp->totalActions(),
            'follow_up_groups'  => [
                ['label' => 'Règlements', 'items' => array_map($this->formatFollowUp(...), $dashboard->followUp->payments)],
                ['label' => 'Préparation', 'items' => array_map($this->formatFollowUp(...), $dashboard->followUp->preparation)],
                ['label' => 'Remise', 'items' => array_map($this->formatFollowUp(...), $dashboard->followUp->handover)],
                ['label' => 'À compléter', 'items' => array_map($this->formatFollowUp(...), $dashboard->followUp->inconsistencies)],
            ],
            'recent_activity'   => array_map($this->formatActivity(...), $recentActivity),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatFollowUp(FollowUpItem $item): array
    {
        return [
            'number'      => $item->order->number,
            'customer'    => $item->order->customerName,
            'reason'      => $item->reason,
            'age'         => $item->ageDays,
            'outstanding' => $item->outstanding->cents() > 0 ? $this->formatMoney($item->outstanding->cents()) : '',
            'url'         => admin_url('admin.php?page=wc-orders&action=edit&id=' . $item->order->id),
        ];
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

    /** @return array<string, string> */
    private function formatActivity(ActivityEntry $entry): array
    {
        $user = $entry->actorId > 0 ? get_userdata($entry->actorId) : null;

        return [
            'date' => wp_date('d/m à H:i', $entry->occurredAt->getTimestamp()),
            'category' => $entry->category->label(),
            'summary' => $entry->summary,
            'actor' => $user ? $user->display_name : 'Système WooCommerce',
        ];
    }
}
