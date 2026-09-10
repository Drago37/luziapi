<?php

/**
 * Test d'intégration local de la page « Fidélité » du pilotage.
 *
 * Exécution : make e2e-loyalty-dashboard-local
 *
 * Contre de vraies commandes WooCommerce passées « Terminée » (crédit des pots
 * par le subscriber branché), pilote le vrai GetLoyaltyDashboardHandler (dépôt de
 * commandes + projecteur clients + journal + lecteur d'économie) et vérifie le
 * récapitulatif et les classements : pots achetés, pots offerts (geste), remise
 * remerciement. Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardHandler;
use LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard\GetLoyaltyDashboardQuery;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceLoyaltyEconomicsReader;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceThankYouDiscount;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;
$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productId = 0;
$createdOrderIds = [];
$keys = [];
$suffix = strtolower(wp_generate_password(10, false, false));
$emailA = 'dash-a-' . $suffix . '@example.test';
$emailB = 'dash-b-' . $suffix . '@example.test';

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot tableau fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $makeOrder = static function (string $name, string $email, int $paidQty, int $giftQty, int $discountCents) use ($productId, &$createdOrderIds): void {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Impossible de créer la commande E2E.');
        }
        $order->set_billing_first_name($name);
        $order->set_billing_email($email);
        $order->add_product(wc_get_product($productId), $paidQty);
        if ($giftQty > 0) {
            $item = new WC_Order_Item_Product();
            $item->set_product(wc_get_product($productId));
            $item->set_quantity($giftQty);
            $item->set_subtotal('0');
            $item->set_total('0');
            $item->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
            $order->add_item($item);
        }
        $order->calculate_totals();
        if ($discountCents > 0) {
            $order->update_meta_data(WooCommerceThankYouDiscount::ORDER_META, (string) $discountCents);
        }
        $order->save();
        $order->update_status('completed');
        $createdOrderIds[] = (int) $order->get_id();
    };

    // Alice : 5 pots achetés, 1 pot offert, remise 2,50 €. Bob : 3 pots.
    $nameA = 'Alice-' . $suffix;
    $nameB = 'Bob-' . $suffix;
    $makeOrder($nameA, $emailA, 5, 1, 250);
    $makeOrder($nameB, $emailB, 3, 0, 0);
    $keys[] = LoyaltyIdentity::fromContact($emailA, '')?->key ?? '';
    $keys[] = LoyaltyIdentity::fromContact($emailB, '')?->key ?? '';

    $handler = new GetLoyaltyDashboardHandler(
        new WooCommerceOrderRepository(wp_timezone()),
        new CustomerHistoryProjector(),
        new WooCommerceLoyaltyEconomicsReader(new WooCommerceEligiblePotCounter()),
        new WordPressClock(),
        new GetCustomerLoyaltyHandler($ledger),
    );
    $view = $handler->handle(new GetLoyaltyDashboardQuery());

    $findRow = static function (array $rows, string $needle) {
        foreach ($rows as $row) {
            if (str_contains($row->name, $needle)) {
                return $row;
            }
        }

        return null;
    };
    $alice = $findRow($view->customers, $nameA);
    $bob = $findRow($view->customers, $nameB);

    $assert('Les deux clients apparaissent au récapitulatif', null !== $alice && null !== $bob);
    $assert('Alice a 5 pots achetés', 5 === ($alice->netPots ?? 0));
    $assert('Alice a 1 pot offert reçu', 1 === ($alice->offeredPots ?? -1));
    $assert('Alice a 2,50 € de remise reçue', 250 === ($alice->discountCents ?? -1));
    $assert('Bob a 3 pots et rien d\'offert ni remisé', 3 === ($bob->netPots ?? 0) && 0 === ($bob->offeredPots ?? -1) && 0 === ($bob->discountCents ?? -1));

    $assert('Le récap est trié par pots : Alice avant Bob', ($view->customers[0]->netPots ?? 0) >= ($view->customers[1]->netPots ?? 0));
    $assert('Top meilleurs clients : Alice en tête', str_contains($view->topBuyers[0]->name ?? '', $nameA));
    $assert('Top « plus profité » : Alice (pot offert)', str_contains($view->topBenefited[0]->name ?? '', $nameA));
    $assert('Top remises : Alice', str_contains($view->topDiscounts[0]->name ?? '', $nameA));

    $assert('Totaux : pots=8, offerts=1, remises=250', 8 === $view->totalPots && 1 === $view->totalOfferedPots && 250 === $view->totalDiscountCents);
} catch (Throwable $exception) {
    $assert('Le scénario se termine sans exception', false, $exception->getMessage());
} finally {
    foreach ($keys as $key) {
        if ('' !== $key) {
            $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $key], ['%s']);
        }
    }
    foreach ($createdOrderIds as $orderId) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
}

$failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['success']));
foreach ($results as $result) {
    WP_CLI::log(sprintf(
        '%s %s%s',
        $result['success'] ? '✓' : '✗',
        $result['label'],
        '' !== $result['detail'] ? ' — ' . $result['detail'] : '',
    ));
}

if ([] !== $failed) {
    WP_CLI::error(sprintf('%d assertion(s) en échec sur %d.', count($failed), count($results)));
}

WP_CLI::success(sprintf('%d assertions du tableau de fidélité validées ; données E2E supprimées.', count($results)));
