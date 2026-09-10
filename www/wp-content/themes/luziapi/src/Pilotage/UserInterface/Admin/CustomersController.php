<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\AssignCustomerCategory\AssignCustomerCategoryCommand;
use LuziApi\Pilotage\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryQuery;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Customer\CustomerTimelineEntry;
use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use Throwable;
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

    public function __construct(
        private GetCustomerDirectoryHandler $getCustomers,
        private AssignCustomerCategoryHandler $assignCategory,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_assign_customer_category', [$this, 'assignCategory']);
    }

    public function render(): void
    {
        $this->assertPermission();

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $customerId = isset($_GET['customer']) ? sanitize_key(wp_unslash((string) $_GET['customer'])) : '';
        $category = isset($_GET['category'])
            ? CustomerCategory::tryFrom(sanitize_key(wp_unslash((string) $_GET['category'])))
            : null;
        $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery($search, $page, 50, $customerId, $category));
        $pageUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers');
        $quickSaleUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale');

        Timber::render('@luziapi_admin/pilotage/customers.twig', [
            'page_url'          => $pageUrl,
            'dashboard_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'products_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'    => $quickSaleUrl,
            'activity_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'        => admin_url('admin.php?page=wc-orders'),
            'new_order_url'     => $quickSaleUrl,
            'new_order_for_customer_url' => $directory->selectedCustomer
                ? add_query_arg('customer', $directory->selectedCustomer->id, $quickSaleUrl)
                : '',
            'action_url'        => admin_url('admin-post.php'),
            'category_nonce'    => wp_create_nonce('luziapi_assign_customer_category'),
            'search'            => $search,
            'selected_category' => $category instanceof CustomerCategory ? $category->value : '',
            'categories'        => array_map(
                static fn (CustomerCategory $customerCategory): array => [
                    'value' => $customerCategory->value,
                    'label' => $customerCategory->label(),
                ],
                CustomerCategory::cases(),
            ),
            'total_customers'   => $directory->totalCustomers,
            'customers'         => array_map($this->formatCustomer(...), $directory->customers),
            'selected_customer' => $directory->selectedCustomer
                ? $this->formatSelectedCustomer($directory->selectedCustomer)
                : null,
            'pagination'        => $this->pagination(
                $pageUrl,
                $search,
                $category instanceof CustomerCategory ? $category->value : '',
                $directory->currentPage,
                $directory->totalPages,
            ),
            'timeline'          => array_map($this->formatTimeline(...), $directory->timeline),
            'notice'            => isset($_GET['customer_notice']) ? sanitize_key(wp_unslash((string) $_GET['customer_notice'])) : '',
        ]);
    }

    public function assignCategory(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_assign_customer_category');
        $customerId = sanitize_key(wp_unslash((string) ($_POST['customer_id'] ?? '')));

        try {
            $category = CustomerCategory::tryFrom(sanitize_key(wp_unslash((string) ($_POST['category'] ?? ''))));
            if (! $category instanceof CustomerCategory) {
                throw new \InvalidArgumentException('Invalid customer category.');
            }
            $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));
            if (! $directory->selectedCustomer instanceof CustomerProfile) {
                throw new \InvalidArgumentException('Unknown customer profile.');
            }
            $this->assignCategory->handle(new AssignCustomerCategoryCommand(
                $directory->selectedCustomer->identityIds,
                $category,
                get_current_user_id(),
            ));
            $this->redirectAfterCategory($customerId, 'category_updated');
        } catch (Throwable) {
            $this->activity->record(
                ActivityCategory::Error,
                'customer_category_update_failed',
                'customer_category',
                null,
                'Modification de la catégorie client échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'category_error');
        }
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
            'category'     => $customer->category->label(),
            'category_key' => $customer->category->value,
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

    private function pagination(string $pageUrl, string $search, string $category, int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        return (string) paginate_links([
            'base'      => add_query_arg(['s' => $search, 'category' => $category, 'paged' => '%#%'], $pageUrl),
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

    private function redirectAfterCategory(string $customerId, string $notice): never
    {
        wp_safe_redirect(add_query_arg([
            'customer' => $customerId,
            'customer_notice' => $notice,
        ], admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers')));
        exit;
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }
}
