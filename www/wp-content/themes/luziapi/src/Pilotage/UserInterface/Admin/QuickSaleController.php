<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleHandler;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLine;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleReceiptFailed;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Product\ProductCatalog;
use LuziApi\Pilotage\Domain\Product\ProductStockSnapshot;
use Throwable;
use Timber\Timber;

final readonly class QuickSaleController
{
    private const PAYMENT_METHODS = [
        'cash'          => 'Espèces',
        'cheque'        => 'Chèque',
        'bank_transfer' => 'Virement bancaire',
        'wero'          => 'Wero',
        'card'          => 'Carte bancaire',
        'other'         => 'Autre',
    ];

    public function __construct(
        private ProductCatalog $products,
        private CreateQuickSaleHandler $createQuickSale,
        private Clock $clock,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_create_quick_sale', [$this, 'create']);
    }

    public function render(): void
    {
        $this->assertPermission();
        $sourceOptions = function_exists('luziapi_order_source_options') ? luziapi_order_source_options() : [];

        Timber::render('@luziapi_admin/pilotage/quick-sale.twig', [
            'page_url'             => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url'  => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'activity_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'action_url'           => admin_url('admin-post.php'),
            'nonce'                => wp_create_nonce('luziapi_create_quick_sale'),
            'request_id'           => wp_generate_uuid4(),
            'products'             => array_map($this->formatProduct(...), array_values(array_filter(
                $this->products->all(),
                static fn (ProductStockSnapshot $product): bool => $product->purchasable,
            ))),
            'sources'              => $sourceOptions,
            'payment_methods'      => self::PAYMENT_METHODS,
            'now'                  => $this->clock->now()->format('Y-m-d\TH:i'),
            'notice'               => isset($_GET['quick_sale_notice']) ? sanitize_key(wp_unslash((string) $_GET['quick_sale_notice'])) : '',
            'created_order_url'    => isset($_GET['order_id']) ? admin_url('admin.php?page=wc-orders&action=edit&id=' . absint($_GET['order_id'])) : '',
        ]);
    }

    public function create(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_create_quick_sale');

        try {
            $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
            if ('' !== $email && ! is_email($email)) {
                throw new InvalidArgumentException('Invalid email.');
            }
            $fulfillment = sanitize_key(wp_unslash((string) ($_POST['fulfillment'] ?? 'immediate')));
            $address = sanitize_text_field(wp_unslash((string) ($_POST['address'] ?? '')));
            $postcode = sanitize_text_field(wp_unslash((string) ($_POST['postcode'] ?? '')));
            $city = sanitize_text_field(wp_unslash((string) ($_POST['city'] ?? '')));
            if ('delivery' === $fulfillment && function_exists('luziapi_is_local_delivery_destination') && ! luziapi_is_local_delivery_destination([
                'country' => 'FR', 'postcode' => $postcode, 'city' => $city,
            ])) {
                throw new InvalidArgumentException('Delivery is limited to Luzillé and Bléré.');
            }
            $occurredAt = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i',
                sanitize_text_field(wp_unslash((string) ($_POST['occurred_at'] ?? ''))),
                $this->clock->timezone(),
            );
            if (false === $occurredAt) {
                throw new InvalidArgumentException('Invalid sale date.');
            }

            $lines = [];
            foreach ((array) ($_POST['quantities'] ?? []) as $productId => $quantity) {
                $quantity = absint($quantity);
                if ($quantity > 0) {
                    $lines[] = new QuickSaleLine(absint($productId), $quantity);
                }
            }
            $created = $this->createQuickSale->handle(new CreateQuickSaleCommand(
                $lines,
                sanitize_text_field(wp_unslash((string) ($_POST['customer_name'] ?? ''))),
                $email,
                sanitize_text_field(wp_unslash((string) ($_POST['phone'] ?? ''))),
                $address,
                $postcode,
                $city,
                sanitize_key(wp_unslash((string) ($_POST['source'] ?? 'market'))),
                sanitize_key(wp_unslash((string) ($_POST['payment_method'] ?? 'cash'))),
                $fulfillment,
                isset($_POST['paid']),
                isset($_POST['send_email']),
                $occurredAt,
                get_current_user_id(),
                sanitize_text_field(wp_unslash((string) ($_POST['request_id'] ?? ''))),
            ));

            $this->redirect($created->alreadyExisted ? 'duplicate' : 'created', $created->orderId);
        } catch (QuickSaleReceiptFailed $exception) {
            $this->recordFailure('Vente rapide créée, mais encaissement non enregistré', $exception->sale->orderId);
            $this->redirect('receipt_error', $exception->sale->orderId);
        } catch (Throwable $exception) {
            $this->recordFailure('Création d’une vente rapide échouée');
            $this->redirect('error');
        }
    }

    /** @return array<string, mixed> */
    private function formatProduct(ProductStockSnapshot $product): array
    {
        return [
            'id'    => $product->id,
            'name'  => $product->name,
            'price' => html_entity_decode(wp_strip_all_tags(wc_price($product->price->cents() / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'stock' => $product->stockQuantity,
        ];
    }

    private function redirect(string $notice, int $orderId = 0): never
    {
        $url = add_query_arg('quick_sale_notice', $notice, admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'));
        if ($orderId > 0) {
            $url = add_query_arg('order_id', $orderId, $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }

    private function recordFailure(string $summary, ?int $orderId = null): void
    {
        $this->activity->record(
            ActivityCategory::Error,
            'quick_sale_failed',
            null !== $orderId ? 'order' : 'quick_sale',
            $orderId,
            $summary,
            [],
            get_current_user_id(),
        );
    }
}
