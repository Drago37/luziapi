<?php

declare(strict_types=1);

namespace LuziApi\Shop\UserInterface\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\CreateHarvestLot\CreateHarvestLotCommand;
use LuziApi\Shop\Application\Command\CreateHarvestLot\CreateHarvestLotHandler;
use LuziApi\Shop\Application\Command\RecordStockMovement\RecordStockMovementCommand;
use LuziApi\Shop\Application\Command\RecordStockMovement\RecordStockMovementHandler;
use LuziApi\Shop\Application\Query\GetInventoryDashboard\GetInventoryDashboardHandler;
use LuziApi\Shop\Domain\Activity\ActivityCategory;
use LuziApi\Shop\Domain\Inventory\HarvestLot;
use LuziApi\Shop\Domain\Inventory\StockMovement;
use LuziApi\Shop\Domain\Inventory\StockMovementType;
use LuziApi\Shop\Domain\Product\ProductStockSnapshot;
use LuziApi\Support\Wp;
use Throwable;
use Timber\Timber;

final readonly class InventoryController
{
    use SurfacesActionErrors;

    public function __construct(
        private GetInventoryDashboardHandler $getInventory,
        private CreateHarvestLotHandler $createLot,
        private RecordStockMovementHandler $recordMovement,
        private Clock $clock,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_create_harvest_lot', [$this, 'createLot']);
        add_action('admin_post_luziapi_record_stock_movement', [$this, 'recordMovement']);
    }

    public function render(): void
    {
        $this->assertPermission();
        $noticeDetail = $this->takeErrorDetail('inventory');
        $view = $this->getInventory->handle();
        $products = [];
        foreach ($view->products as $product) {
            $products[$product->id] = $product;
        }
        $lots = [];
        foreach ($view->lots as $lot) {
            $lots[$lot->id] = $lot;
        }
        $wooStock = array_sum(array_map(
            static fn (ProductStockSnapshot $product): int => $product->stockQuantity ?? 0,
            $view->products,
        ));
        $lotStock = array_sum(array_map(static fn (HarvestLot $lot): int => $lot->stockRemaining, $view->lots));

        Timber::render('@luziapi_admin/pilotage/inventory.twig', [

            'pilotage_tabs' => PilotageTabs::links('inventory'),
            'page_url'             => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'dashboard_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url'  => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'quick_sale_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'         => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'loyalty_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty'),
            'orders_url'           => admin_url('admin.php?page=wc-orders'),
            'action_url'           => admin_url('admin-post.php'),
            'lot_nonce'            => wp_create_nonce('luziapi_create_harvest_lot'),
            'movement_nonce'       => wp_create_nonce('luziapi_record_stock_movement'),
            'now'                  => $this->clock->now()->format('Y-m-d\TH:i'),
            'today'                => $this->clock->now()->format('Y-m-d'),
            'products'             => array_map($this->formatProduct(...), $view->products),
            'lots'                 => array_map(fn (HarvestLot $lot): array => $this->formatLot($lot, $products), $view->lots),
            'movements'            => array_map(fn (StockMovement $movement): array => $this->formatMovement($movement, $products, $lots), $view->movements),
            'movement_types'       => array_map(
                static fn (StockMovementType $type): array => ['value' => $type->value, 'label' => $type->label()],
                array_values(array_filter(StockMovementType::cases(), static fn (StockMovementType $type): bool => $type->isManual())),
            ),
            'metrics'              => [
                ['label' => 'Stock WooCommerce', 'value' => $wooStock . ' pots'],
                ['label' => 'Stock affecté aux lots', 'value' => $lotStock . ' pots'],
                ['label' => 'Écart non affecté', 'value' => ($wooStock - $lotStock) . ' pots'],
                ['label' => 'Lots enregistrés', 'value' => (string) count($view->lots)],
            ],
            'notice'               => isset($_GET['inventory_notice']) ? sanitize_key(wp_unslash(Wp::str($_GET['inventory_notice']))) : '',
            'notice_detail'        => $noticeDetail,
        ]);
    }

    public function createLot(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_create_harvest_lot');
        $stockAlreadyRecorded = 'existing' === sanitize_key(wp_unslash(Wp::str($_POST['stock_registration'] ?? 'new')));

        try {
            $this->createLot->handle(new CreateHarvestLotCommand(
                sanitize_text_field(wp_unslash(Wp::str($_POST['lot_number'] ?? ''))),
                absint(Wp::str($_POST['product_id'] ?? 0)),
                $this->parseDate('harvested_at', '!Y-m-d'),
                $this->parseDate('jarred_at', 'Y-m-d\TH:i'),
                sanitize_text_field(wp_unslash(Wp::str($_POST['apiary_origin'] ?? ''))),
                sanitize_text_field(wp_unslash(Wp::str($_POST['variety'] ?? ''))),
                absint(Wp::str($_POST['quantity_jarred'] ?? 0)),
                $stockAlreadyRecorded,
                get_current_user_id(),
            ));
            $this->redirect($stockAlreadyRecorded ? 'lot_attached' : 'lot_created');
        } catch (Throwable $exception) {
            $this->recordFailure('Enregistrement de la récolte échoué', $exception);
            $this->redirect('error');
        }
    }

    public function recordMovement(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_record_stock_movement');

        try {
            $type = StockMovementType::tryFrom(sanitize_key(wp_unslash(Wp::str($_POST['movement_type'] ?? ''))));
            if (! $type instanceof StockMovementType) {
                throw new InvalidArgumentException('Invalid stock movement type.');
            }
            $quantity = (int) sanitize_text_field(wp_unslash(Wp::str($_POST['quantity'] ?? '0')));
            $lotId = absint(Wp::str($_POST['lot_id'] ?? 0)) ?: null;
            $this->recordMovement->handle(new RecordStockMovementCommand(
                absint(Wp::str($_POST['product_id'] ?? 0)),
                $lotId,
                $type,
                $quantity,
                sanitize_text_field(wp_unslash(Wp::str($_POST['reason'] ?? ''))),
                $this->parseDate('occurred_at'),
                get_current_user_id(),
            ));
            $this->redirect('movement_recorded');
        } catch (Throwable $exception) {
            $this->recordFailure('Enregistrement du mouvement de stock échoué', $exception);
            $this->redirect('error');
        }
    }

    /** @return array<string, mixed> */
    private function formatProduct(ProductStockSnapshot $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'stock' => $product->stockQuantity];
    }

    /**
     * @param array<int, ProductStockSnapshot> $products
     *
     * @return array<string, mixed>
     */
    private function formatLot(HarvestLot $lot, array $products): array
    {
        return [
            'id'              => $lot->id,
            'lot_number'      => $lot->lotNumber,
            'product_id'      => $lot->productId,
            'product'         => $products[$lot->productId]->name ?? 'Produit supprimé',
            'year'            => $lot->harvestYear,
            'harvested_at'    => wp_date('d/m/Y', $lot->harvestedAt->getTimestamp()),
            'jarred_at'       => wp_date('d/m/Y à H:i', $lot->jarredAt->getTimestamp()),
            'origin'          => $lot->apiaryOrigin,
            'variety'         => $lot->variety,
            'quantity_jarred' => $lot->quantityJarred,
            'stock_remaining' => $lot->stockRemaining,
            'stock_origin'    => $lot->stockAlreadyRecorded ? 'Stock existant rattaché' : 'Nouvelle mise en pots',
        ];
    }

    /**
     * @param array<int, ProductStockSnapshot> $products
     * @param array<int, HarvestLot>           $lots
     *
     * @return array<string, mixed>
     */
    private function formatMovement(StockMovement $movement, array $products, array $lots): array
    {
        return [
            'sequence'    => str_pad((string) $movement->sequenceNumber, 6, '0', STR_PAD_LEFT),
            'date'        => wp_date('d/m/Y à H:i', $movement->occurredAt->getTimestamp()),
            'product'     => $products[$movement->productId]->name ?? 'Produit supprimé',
            'lot'         => null !== $movement->lotId ? ($lots[$movement->lotId]->lotNumber ?? 'Lot supprimé') : 'Non affecté',
            'type'        => $movement->type->label(),
            'reason'      => $movement->reason,
            'order_id'    => $movement->orderId,
            'order_url'   => $movement->orderId ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $movement->orderId) : '',
            'delta'       => ($movement->quantityDelta > 0 ? '+' : '') . $movement->quantityDelta,
            'delta_value' => $movement->quantityDelta,
        ];
    }

    private function parseDate(string $field, string $format = 'Y-m-d\TH:i'): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            $format,
            sanitize_text_field(wp_unslash(Wp::str($_POST[$field] ?? ''))),
            $this->clock->timezone(),
        );
        if (false === $date) {
            throw new InvalidArgumentException('Invalid inventory date.');
        }

        return $date;
    }

    private function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg('inventory_notice', $notice, admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory')));
        exit;
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }

    private function recordFailure(string $summary, Throwable $exception): void
    {
        // Ne plus avaler la cause : on la met de côté pour l'afficher sur la page
        // de retour, et on la consigne dans le journal d'activité du pilotage.
        $this->rememberErrorDetail('inventory', $exception);
        $this->activity->record(
            ActivityCategory::Error,
            'inventory_operation_failed',
            'inventory',
            null,
            $summary,
            ['erreur' => $exception->getMessage()],
            get_current_user_id(),
        );
    }
}
