<?php

declare(strict_types=1);

namespace LuziApi\Shop\Bootstrap;

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionHandler;
use LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersHandler;
use LuziApi\Newsletter\Infrastructure\Brevo\BrevoSubscriberDirectory;
use LuziApi\Newsletter\Infrastructure\Brevo\BrevoSubscriberWriter;
use LuziApi\Newsletter\Infrastructure\NullSubscriberDirectory;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountHandler;
use LuziApi\Shop\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountHandler;
use LuziApi\Shop\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Shop\Application\Command\CreateHarvestLot\CreateHarvestLotHandler;
use LuziApi\Shop\Application\Command\CreateQuickSale\CreateQuickSaleHandler;
use LuziApi\Shop\Application\Command\RecordOrderReceipt\RecordOrderReceiptHandler;
use LuziApi\Shop\Application\Command\RecordOrderStockMovement\OrderStockMovementRecorder;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Shop\Application\Command\RecordStockMovement\RecordStockMovementHandler;
use LuziApi\Shop\Application\Command\ReverseReceipt\ReverseReceiptHandler;
use LuziApi\Shop\Application\Command\SaveCustomerProfile\SaveCustomerProfileHandler;
use LuziApi\Shop\Application\Query\GetActivityLog\GetActivityLogHandler;
use LuziApi\Shop\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Shop\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Shop\Application\Query\GetInventoryDashboard\GetInventoryDashboardHandler;
use LuziApi\Shop\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Shop\Application\Query\GetProductDashboard\GetProductDashboardHandler;
use LuziApi\Shop\Application\Query\GetReceiptRegister\GetReceiptRegisterHandler;
use LuziApi\Shop\Application\Query\GetTaxDeclaration\GetTaxDeclarationHandler;
use LuziApi\Shop\Application\Query\SearchAddress\SearchAddressHandler;
use LuziApi\Shop\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Shop\Domain\FollowUp\FollowUpProjector;
use LuziApi\Shop\Domain\Product\ProductPerformanceProjector;
use LuziApi\Shop\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Shop\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Shop\Domain\Sales\AnnualSalesCalculator;
use LuziApi\Shop\Domain\Tax\MicroBaCalculator;
use LuziApi\Shop\Infrastructure\Http\BanAddressLookup;
use LuziApi\Shop\Infrastructure\Loyalty\LoyaltyModuleRewardsReader;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceActivitySubscriber;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceCustomerTimelineRepository;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceLoyaltyEconomicsReader;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderDiscountWriter;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderLotSelector;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderStockSubscriber;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderVolumeDiscountWriter;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrphanReceiptSubscriber;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceProductCatalog;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceReceiptSubscriber;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceStockLevelGateway;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceVolumeDiscountFixSubscriber;
use LuziApi\Shop\Infrastructure\WordPress\AuditedCustomerCategoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\AuditedInventoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Shop\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Shop\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressClock;
use LuziApi\Shop\Infrastructure\WordPress\WordPressCustomerCategoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressCustomerProfileRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressInventoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressReceiptRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressTaxSettings;
use LuziApi\Shop\UserInterface\Admin\ActivityController;
use LuziApi\Shop\UserInterface\Admin\AddressLookupController;
use LuziApi\Shop\UserInterface\Admin\AdminMenu;
use LuziApi\Shop\UserInterface\Admin\AssetLoader;
use LuziApi\Shop\UserInterface\Admin\CustomersController;
use LuziApi\Shop\UserInterface\Admin\DashboardController;
use LuziApi\Shop\UserInterface\Admin\InventoryController;
use LuziApi\Shop\UserInterface\Admin\LoyaltyController;
use LuziApi\Shop\UserInterface\Admin\PilotageController;
use LuziApi\Shop\UserInterface\Admin\ProductsController;
use LuziApi\Shop\UserInterface\Admin\QuickSaleController;
use LuziApi\Shop\UserInterface\Admin\ReceiptsController;
use LuziApi\Shop\UserInterface\Admin\SubscribersController;
use LuziApi\Shop\UserInterface\Admin\TaxDeclarationController;
use wpdb;

final class ShopServiceProvider
{
    private static bool $booted = false;

    public static function boot(string $themeDirectory, string $themeUri): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_filter('timber/locations', static function (array $locations) use ($themeDirectory): array {
            $existing = $locations['luziapi_admin'] ?? [];
            $existing = is_array($existing) ? $existing : [];
            $existing[] = $themeDirectory . '/resources/views/admin';
            $locations['luziapi_admin'] = $existing;

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
        $customerProfiles = new WordPressCustomerProfileRepository($wpdb, $schema);
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
            $customerProfiles,
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
        $loyaltyHandler = \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::customerLoyaltyHandler();
        $quickSaleController = new QuickSaleController(
            $products,
            new CreateQuickSaleHandler(new WooCommerceQuickSaleOrderWriter(), $recordReceipt, $clock, $loyaltyHandler),
            $customerHandler,
            $clock,
            $activity,
            $loyaltyHandler,
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
        $brevoKeyOption = get_option('sib_api_key_v3', '');
        $brevoKey = is_string($brevoKeyOption) ? $brevoKeyOption : '';
        $brevoListId = 2;
        if (defined('LUZIAPI_BREVO_LIST_ID')) {
            $definedListId = constant('LUZIAPI_BREVO_LIST_ID');
            if (is_numeric($definedListId)) {
                $brevoListId = (int) $definedListId;
            }
        }
        $subscribers = '' !== trim($brevoKey)
            ? new BrevoSubscriberDirectory($brevoKey, $brevoListId)
            : new NullSubscriberDirectory();
        $updateSubscription = '' !== trim($brevoKey)
            ? new UpdateSubscriptionHandler(new BrevoSubscriberWriter($brevoKey, $brevoListId))
            : null;

        $customersController = new CustomersController(
            $customerHandler,
            new AssignCustomerCategoryHandler($customerCategories, $clock),
            $activity,
            \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::customerLoyaltyHandler(),
            new ApplyThankYouDiscountHandler(
                new WooCommerceOrderDiscountWriter(),
                $recordReceipt,
                $receipts,
                $activity,
                $clock,
            ),
            \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::loyaltyAdjustmentHandler(),
            $subscribers,
            new SaveCustomerProfileHandler($customerProfiles, $clock),
            $updateSubscription,
            \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::mergeIdentitiesHandler(),
            \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::identityLinks(),
        );
        $loyaltyRewardsHandler = \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::customerLoyaltyHandler();
        $loyaltyController = new LoyaltyController(new GetLoyaltyDashboardHandler(
            $orders,
            new CustomerHistoryProjector(),
            new WooCommerceLoyaltyEconomicsReader(new WooCommerceEligiblePotCounter()),
            $clock,
            null !== $loyaltyRewardsHandler
                ? new LoyaltyModuleRewardsReader($loyaltyRewardsHandler)
                : null,
        ));
        $controller = new PilotageController(
            new DashboardController($handler, new GetActivityLogHandler($activityRepository), $clock),
            $customersController,
            $taxController,
            $receiptsController,
            new ProductsController(new GetProductDashboardHandler($products, $orders, new ProductPerformanceProjector(), $clock)),
            $inventoryController,
            $quickSaleController,
            $activityController,
            $loyaltyController,
            new SubscribersController(new GetSubscribersHandler($subscribers)),
        );

        add_action('init', [$schema, 'migrate'], 1);
        $receiptsController->register();
        $customersController->register();
        (new AddressLookupController(new SearchAddressHandler(new BanAddressLookup())))->register();
        $quickSaleController->register();
        $inventoryController->register();
        $taxController->register();
        $activityController->register();
        (new WooCommerceOrderStockSubscriber(new OrderStockMovementRecorder($inventory, $clock), $activity))->register();
        (new WooCommerceOrderLotSelector($inventory, $activity))->register();
        (new WooCommerceActivitySubscriber($activity))->register();
        (new WooCommerceReceiptSubscriber(new RecordOrderReceiptHandler($receipts, $recordReceipt), $clock))->register();
        (new WooCommerceOrphanReceiptSubscriber($receipts, luziapi_logger()))->register();
        (new WooCommerceVolumeDiscountFixSubscriber(
            new ApplyMissingVolumeDiscountHandler(
                new WooCommerceOrderVolumeDiscountWriter(),
                $recordReceipt,
                $receipts,
                $activity,
                $clock,
            ),
            luziapi_logger(),
        ))->register();
        (new AdminMenu($controller))->register();
        (new AssetLoader($themeDirectory, $themeUri))->register();
    }
}
