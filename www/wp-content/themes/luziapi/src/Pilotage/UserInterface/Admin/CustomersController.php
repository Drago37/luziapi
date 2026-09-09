<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryQuery;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Customer\CustomerTimelineEntry;
use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use Timber\Timber;

final readonly class CustomersController
{
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

    public function __construct(private GetCustomerDirectoryHandler $getCustomers)
    {
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $customerId = isset($_GET['customer']) ? sanitize_key(wp_unslash((string) $_GET['customer'])) : '';
        $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery($search, $page, 50, $customerId));
        $pageUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers');

        Timber::render('@luziapi_admin/pilotage/customers.twig', [
            'page_url'          => $pageUrl,
            'dashboard_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'products_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'    => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'        => admin_url('admin.php?page=wc-orders'),
            'new_order_url'     => admin_url('admin.php?page=wc-orders&action=new'),
            'search'            => $search,
            'total_customers'   => $directory->totalCustomers,
            'customers'         => array_map($this->formatCustomer(...), $directory->customers),
            'selected_customer' => $directory->selectedCustomer
                ? $this->formatSelectedCustomer($directory->selectedCustomer)
                : null,
            'pagination'        => $this->pagination(
                $pageUrl,
                $search,
                $directory->currentPage,
                $directory->totalPages,
            ),
            'timeline'          => array_map($this->formatTimeline(...), $directory->timeline),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomer(CustomerProfile $customer): array
    {
        $lastOrder = $customer->lastOrder();
        $sourceLabels = function_exists('luziapi_order_source_options') ? luziapi_order_source_options() : [];

        return [
            'id'           => $customer->id,
            'name'         => $customer->name,
            'city'         => $customer->city,
            'emails'       => $customer->emails,
            'phones'       => array_map(static fn (string $phone): array => [
                'label' => $phone,
                'href'  => NormalizedPhone::fromString($phone)?->international() ?? $phone,
            ], $customer->phones),
            'last_order'   => $this->formatOrder($lastOrder),
            'orders_count' => $customer->validOrdersCount,
            'total'        => $this->formatMoney($customer->orderedTotal->cents()),
            'collected'    => $this->formatMoney($customer->collectedTotal->cents()),
            'products'     => $customer->favoriteProducts,
            'sources'      => array_map(static fn (string $source): string => $sourceLabels[$source] ?? $source, $customer->sources),
            'details_url'  => add_query_arg('customer', $customer->id, admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSelectedCustomer(CustomerProfile $customer): array
    {
        $formatted = $this->formatCustomer($customer);
        $formatted['orders'] = array_map($this->formatOrder(...), $customer->orders);

        return $formatted;
    }

    /**
     * @return array<string, string>
     */
    private function formatOrder(OrderSnapshot $order): array
    {
        return [
            'number' => $order->number,
            'url'    => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->id),
            'date'   => wp_date('d/m/Y à H:i', $order->createdAt->getTimestamp()),
            'status' => self::STATUS_LABELS[$order->status] ?? $order->status,
            'total'  => $this->formatMoney($order->total->cents()),
        ];
    }

    /** @return array<string, mixed> */
    private function formatTimeline(CustomerTimelineEntry $entry): array
    {
        return [
            'date'         => wp_date('d/m/Y à H:i', $entry->occurredAt->getTimestamp()),
            'content'      => $entry->content,
            'public'       => $entry->public,
            'visibility'   => $entry->public ? 'Note client' : 'Note privée',
            'order_number' => $entry->orderNumber,
            'order_url'    => admin_url('admin.php?page=wc-orders&action=edit&id=' . $entry->orderId),
        ];
    }

    private function pagination(string $pageUrl, string $search, int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        return (string) paginate_links([
            'base'      => add_query_arg(['s' => $search, 'paged' => '%#%'], $pageUrl),
            'format'    => '',
            'current'   => $currentPage,
            'total'     => $totalPages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
