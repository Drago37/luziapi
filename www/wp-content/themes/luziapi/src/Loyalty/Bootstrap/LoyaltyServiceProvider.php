<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Bootstrap;

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditHandler;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceLoyaltyEarningSubscriber;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressClock;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;
use wpdb;

/**
 * Composition root du programme de fidélité. Doit démarrer AVANT le Pilotage :
 * la fiche client lit `customerLoyaltyHandler()` pour afficher la carte fidélité.
 */
final class LoyaltyServiceProvider
{
    private static bool $booted = false;
    private static ?GetCustomerLoyaltyHandler $customerLoyaltyHandler = null;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $logger = luziapi_logger();
        $clock = new WordPressClock();
        $schema = new LoyaltySchemaManager($wpdb);
        $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());

        self::$customerLoyaltyHandler = new GetCustomerLoyaltyHandler($ledger);

        add_action('init', [$schema, 'migrate'], 1);
        (new WooCommerceLoyaltyEarningSubscriber(
            new RecordCompletedOrderHandler($ledger, $clock),
            new ReverseOrderCreditHandler($ledger, $clock),
            new RecordRewardConsumptionHandler($ledger, $clock),
            new ReverseRewardConsumptionHandler($ledger, $clock),
            new WooCommerceEligiblePotCounter(),
            new WooCommerceOrderIdentityResolver(),
            $logger,
        ))->register();
    }

    /**
     * Handler de lecture partagé avec le Pilotage (fiche client). `null` tant que
     * `boot()` n'a pas été appelé ou si la base n'est pas disponible.
     */
    public static function customerLoyaltyHandler(): ?GetCustomerLoyaltyHandler
    {
        return self::$customerLoyaltyHandler;
    }
}
