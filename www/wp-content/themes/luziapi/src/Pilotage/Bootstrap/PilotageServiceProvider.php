<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Bootstrap;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Pilotage\Application\Command\CreateHarvestLot\CreateHarvestLotHandler;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleHandler;
use LuziApi\Pilotage\Application\Command\RecordOrderStockMovement\OrderStockMovementRecorder;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Command\RecordStockMovement\RecordStockMovementHandler;
use LuziApi\Pilotage\Application\Command\ReverseReceipt\ReverseReceiptHandler;
use LuziApi\Pilotage\Application\Query\GetActivityLog\GetActivityLogHandler;
use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Pilotage\Application\Query\GetInventoryDashboard\GetInventoryDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetProductDashboard\GetProductDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetReceiptRegister\GetReceiptRegisterHandler;
use LuziApi\Pilotage\Application\Query\GetTaxDeclaration\GetTaxDeclarationHandler;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\FollowUp\FollowUpProjector;
use LuziApi\Pilotage\Domain\Product\ProductPerformanceProjector;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Pilotage\Domain\Sales\AnnualSalesCalculator;
use LuziApi\Pilotage\Domain\Tax\MicroBaCalculator;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceActivitySubscriber;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceCustomerTimelineRepository;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderLotSelector;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderStockSubscriber;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceProductCatalog;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceStockLevelGateway;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedCustomerCategoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedInventoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressCustomerCategoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressInventoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressTaxSettings;
use LuziApi\Pilotage\UserInterface\Admin\ActivityController;
use LuziApi\Pilotage\UserInterface\Admin\AdminMenu;
use LuziApi\Pilotage\UserInterface\Admin\AssetLoader;
use LuziApi\Pilotage\UserInterface\Admin\CustomersController;
use LuziApi\Pilotage\UserInterface\Admin\DashboardController;
use LuziApi\Pilotage\UserInterface\Admin\InventoryController;
use LuziApi\Pilotage\UserInterface\Admin\PilotageController;
use LuziApi\Pilotage\UserInterface\Admin\ProductsController;
use LuziApi\Pilotage\UserInterface\Admin\QuickSaleController;
use LuziApi\Pilotage\UserInterface\Admin\ReceiptsController;
use LuziApi\Pilotage\UserInterface\Admin\TaxDeclarationController;
use wpdb;

final class PilotageServiceProvider
{
    private static bool $booted = false;

    public static function boot(string $themeDirectory, string $themeUri): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_filter('timber/locations', static function (array $locations) use ($themeDirectory): array {
            $locations['luziapi_admin'][] = $themeDirectory . '/resources/views/admin';

            return $locations;
        });

        $clock = new WordPressClock();
        $orders = new WooCommerceOrderRepository($clock->timezone());
        $products = new WooCommerceProductCatalog();
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }
        $schema = new PilotageSchemaManager($wpdb);
        $activityRepository = new WordPressActivityRepository($wpdb, $schema, $clock->timezone());
        $activity = new ActivityRecorder($activityRepository, $clock);
        $customerCategories = new AuditedCustomerCategoryRepository(
            new WordPressCustomerCategoryRepository($wpdb, $schema),
            $activity,
        );
        $receipts = new AuditedReceiptRepository(
            new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()),
            $activity,
        );
        $inventory = new AuditedInventoryRepository(
            new WordPressInventoryRepository($wpdb, $schema, $clock->timezone()),
            $activity,
        );
        $stock = new WooCommerceStockLevelGateway();
        $receiptCalculator = new AnnualReceiptCalculator();
        $recordReceipt = new RecordReceiptHandler($receipts, $clock);
        $taxSettings = new WordPressTaxSettings();
        $handler = new GetAnnualDashboardHandler(
            $orders,
            new AnnualSalesCalculator(),
            $receipts,
            $receiptCalculator,
            new FollowUpProjector(),
            $clock,
        );
        $customerHandler = new GetCustomerDirectoryHandler(
            $orders,
            new CustomerHistoryProjector(),
            $customerCategories,
            $receipts,
            new WooCommerceCustomerTimelineRepository($clock->timezone()),
            $clock,
        );
        $receiptHandler = new GetReceiptRegisterHandler(
            $receipts,
            $orders,
            $receiptCalculator,
            new ReceiptReconciliationProjector(),
            $clock,
        );
        $taxHandler = new GetTaxDeclarationHandler(
            $receipts,
            $orders,
            $receiptCalculator,
            new MicroBaCalculator(),
            $taxSettings,
            $clock,
        );
        $receiptsController = new ReceiptsController(
            $receiptHandler,
            $recordReceipt,
            new ReverseReceiptHandler($receipts, $clock),
            $receipts,
            $clock,
            $activity,
        );
        $quickSaleController = new QuickSaleController(
            $products,
            new CreateQuickSaleHandler(new WooCommerceQuickSaleOrderWriter(), $recordReceipt, $clock),
            $clock,
            $activity,
        );
        $inventoryController = new InventoryController(
            new GetInventoryDashboardHandler($products, $inventory),
            new CreateHarvestLotHandler($inventory, $products, $stock, $clock),
            new RecordStockMovementHandler($inventory, $stock, $clock),
            $clock,
            $activity,
        );
        $taxController = new TaxDeclarationController($taxHandler, $handler, $taxSettings, $activity);
        $activityController = new ActivityController(new GetActivityLogHandler($activityRepository), $activity, $clock);
        $customersController = new CustomersController(
            $customerHandler,
            new AssignCustomerCategoryHandler($customerCategories, $clock),
            $activity,
        );
        $controller = new PilotageController(
            new DashboardController($handler, new GetActivityLogHandler($activityRepository), $clock),
            $customersController,
            $taxController,
            $receiptsController,
            new ProductsController(new GetProductDashboardHandler($products, $orders, new ProductPerformanceProjector(), $clock)),
            $inventoryController,
            $quickSaleController,
            $activityController,
        );

        add_action('init', [$schema, 'migrate'], 1);
        $receiptsController->register();
        $customersController->register();
        $quickSaleController->register();
        $inventoryController->register();
        $taxController->register();
        $activityController->register();
        (new WooCommerceOrderStockSubscriber(new OrderStockMovementRecorder($inventory, $clock), $activity))->register();
        (new WooCommerceOrderLotSelector($inventory, $activity))->register();
        (new WooCommerceActivitySubscriber($activity))->register();
        (new AdminMenu($controller))->register();
        (new AssetLoader($themeDirectory, $themeUri))->register();
    }
}
