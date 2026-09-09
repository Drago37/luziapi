<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Bootstrap;

use LuziApi\Pilotage\Application\Query\GetAnnualDashboard\GetAnnualDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Sales\AnnualSalesCalculator;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\UserInterface\Admin\AdminMenu;
use LuziApi\Pilotage\UserInterface\Admin\AssetLoader;
use LuziApi\Pilotage\UserInterface\Admin\CustomersController;
use LuziApi\Pilotage\UserInterface\Admin\DashboardController;
use LuziApi\Pilotage\UserInterface\Admin\PilotageController;
use LuziApi\Pilotage\UserInterface\Admin\TaxDeclarationController;

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
        $handler = new GetAnnualDashboardHandler($orders, new AnnualSalesCalculator(), $clock);
        $customerHandler = new GetCustomerDirectoryHandler($orders, new CustomerHistoryProjector(), $clock);
        $controller = new PilotageController(
            new DashboardController($handler),
            new CustomersController($customerHandler),
            new TaxDeclarationController($handler),
        );

        (new AdminMenu($controller))->register();
        (new AssetLoader($themeDirectory, $themeUri))->register();
    }
}
