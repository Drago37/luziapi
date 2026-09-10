<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Query\GetActivityLog\GetActivityLogHandler;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Activity\ActivityEntry;
use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use Timber\Timber;

final readonly class ActivityController
{
    public function __construct(
        private GetActivityLogHandler $getActivity,
        private ActivityRecorder $recorder,
        private Clock $clock,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_export_activity', [$this, 'export']);
    }

    public function render(): void
    {
        $this->assertPermission();
        $filter = $this->filter();
        $entries = $this->getActivity->handle($filter);
        $formatted = array_map($this->formatEntry(...), $entries);

        Timber::render('@luziapi_admin/pilotage/activity.twig', [
            'page_url'             => $this->pageUrl(),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url'  => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'loyalty_url'          => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'from'                 => $filter->from->format('Y-m-d'),
            'to'                   => $filter->to->format('Y-m-d'),
            'selected_category'    => $filter->category instanceof ActivityCategory ? $filter->category->value : '',
            'search'               => $filter->search,
            'categories'           => array_map(
                static fn (ActivityCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
                ActivityCategory::cases(),
            ),
            'entries'              => $formatted,
            'metrics'              => [
                ['label' => 'Actions affichées', 'value' => (string) count($entries)],
                ['label' => 'Actions humaines', 'value' => (string) count(array_filter($entries, static fn (ActivityEntry $entry): bool => $entry->actorId > 0))],
                ['label' => 'Actions système', 'value' => (string) count(array_filter($entries, static fn (ActivityEntry $entry): bool => 0 === $entry->actorId))],
                ['label' => 'Erreurs tracées', 'value' => (string) count(array_filter($entries, static fn (ActivityEntry $entry): bool => ActivityCategory::Error === $entry->category))],
            ],
            'export_url'           => wp_nonce_url(add_query_arg([
                'action' => 'luziapi_export_activity',
                'from' => $filter->from->format('Y-m-d'),
                'to' => $filter->to->format('Y-m-d'),
                'category' => $filter->category instanceof ActivityCategory ? $filter->category->value : '',
                'search' => $filter->search,
            ], admin_url('admin-post.php')), 'luziapi_export_activity'),
        ]);
    }

    public function export(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_export_activity');
        $filter = $this->filter(2000);
        $entries = $this->getActivity->handle($filter);
        $this->recorder->record(
            ActivityCategory::Export,
            'activity_exported',
            'activity_log',
            null,
            'Journal d’activité exporté en CSV',
            ['Période' => $filter->from->format('d/m/Y') . ' – ' . $filter->to->format('d/m/Y')],
            get_current_user_id(),
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="luziapi-journal-activite-' . $filter->from->format('Ymd') . '-' . $filter->to->format('Ymd') . '.csv"');
        $output = fopen('php://output', 'wb');
        if (false === $output) {
            wp_die('Impossible de générer l’export.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Date', 'Auteur', 'Domaine', 'Action', 'Type objet', 'Identifiant', 'Résumé', 'Détails'], ';');
        foreach ($entries as $entry) {
            $details = implode(' | ', array_map(
                static fn (string $key, string $value): string => $key . ' : ' . $value,
                array_keys($entry->details),
                array_values($entry->details),
            ));
            fputcsv($output, array_map($this->csvCell(...), [
                $entry->occurredAt->format('d/m/Y H:i:s'),
                $this->actorLabel($entry->actorId),
                $entry->category->label(),
                $entry->action,
                $entry->objectType,
                null !== $entry->objectId ? (string) $entry->objectId : '',
                $entry->summary,
                $details,
            ]), ';');
        }
        fclose($output);
        exit;
    }

    private function filter(int $limit = 500): ActivityFilter
    {
        $now = $this->clock->now();
        $from = $this->parseDate('from', $now->modify('-30 days')->setTime(0, 0));
        $to = $this->parseDate('to', $now->setTime(23, 59, 59), true);
        if ($from > $to) {
            [$from, $to] = [$to->setTime(0, 0), $from->setTime(23, 59, 59)];
        }
        $category = isset($_GET['category'])
            ? ActivityCategory::tryFrom(sanitize_key(wp_unslash((string) $_GET['category'])))
            : null;
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';

        return new ActivityFilter($from, $to, $category, $search, $limit);
    }

    private function parseDate(string $name, DateTimeImmutable $fallback, bool $endOfDay = false): DateTimeImmutable
    {
        if (! isset($_GET[$name])) {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sanitize_text_field(wp_unslash((string) $_GET[$name])),
            $this->clock->timezone(),
        );

        return false === $date ? $fallback : ($endOfDay ? $date->setTime(23, 59, 59) : $date);
    }

    /** @return array<string, mixed> */
    private function formatEntry(ActivityEntry $entry): array
    {
        return [
            'date' => wp_date('d/m/Y à H:i:s', $entry->occurredAt->getTimestamp()),
            'actor' => $this->actorLabel($entry->actorId),
            'category' => $entry->category->label(),
            'category_key' => $entry->category->value,
            'summary' => $entry->summary,
            'details' => $entry->details,
            'object_label' => $this->objectLabel($entry),
            'object_url' => $this->objectUrl($entry),
        ];
    }

    private function actorLabel(int $actorId): string
    {
        if (0 === $actorId) {
            return 'Système WooCommerce';
        }
        $user = get_userdata($actorId);

        return $user ? $user->display_name : 'Utilisateur n°' . $actorId;
    }

    private function objectLabel(ActivityEntry $entry): string
    {
        return match ($entry->objectType) {
            'order' => 'Commande n°' . $entry->objectId,
            'customer' => 'Client n°' . $entry->objectId,
            'harvest_lot' => 'Récolte n°' . $entry->objectId,
            'receipt' => 'Écriture n°' . $entry->objectId,
            default => $entry->objectId ? ucfirst(str_replace('_', ' ', $entry->objectType)) . ' n°' . $entry->objectId : ucfirst(str_replace('_', ' ', $entry->objectType)),
        };
    }

    private function objectUrl(ActivityEntry $entry): string
    {
        return match ($entry->objectType) {
            'order' => admin_url('admin.php?page=wc-orders&action=edit&id=' . $entry->objectId),
            'customer' => admin_url('user-edit.php?user_id=' . $entry->objectId),
            'harvest_lot', 'stock_movement' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'receipt' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            default => '',
        };
    }

    private function pageUrl(): string
    {
        return admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity');
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
