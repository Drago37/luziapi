<?php

/**
 * Test d'intégration local de la page « Fidélité » du pilotage (par année).
 *
 * Exécution : make e2e-loyalty-dashboard-local
 *
 * Contre de vraies commandes WooCommerce « Terminée », datées sur deux années,
 * pilote le vrai GetLoyaltyDashboardHandler et vérifie le récapitulatif par
 * client, les classements et surtout le FILTRAGE PAR ANNÉE (pots achetés, pots
 * offerts, remise remerciement). Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
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
$nameA = 'Alice-' . $suffix;
$nameB = 'Bob-' . $suffix;
$thisYear = (int) current_time('Y');
$lastYear = $thisYear - 1;

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot tableau fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $makeOrder = static function (string $name, string $email, int $paidQty, int $giftQty, int $discountCents, int $year) use ($productId, &$createdOrderIds): void {
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
        // Date la commande sur l'année voulue (createdAt et paidAt).
        $order->set_date_created($year . '-06-15 10:00:00');
        $order->set_date_paid($year . '-06-15 10:00:00');
        $order->save();
        $createdOrderIds[] = (int) $order->get_id();
    };

    // Alice : 5 pots + 1 offert + 2,50 € cette année, et 4 pots l'an dernier.
    // Bob : 3 pots cette année.
    $makeOrder($nameA, $emailA, 5, 1, 250, $thisYear);
    $makeOrder($nameA, $emailA, 4, 0, 0, $lastYear);
    $makeOrder($nameB, $emailB, 3, 0, 0, $thisYear);
    $keys[] = LoyaltyIdentity::fromContact($emailA, '')?->key ?? '';
    $keys[] = LoyaltyIdentity::fromContact($emailB, '')?->key ?? '';

    $handler = new GetLoyaltyDashboardHandler(
        new WooCommerceOrderRepository(wp_timezone()),
        new CustomerHistoryProjector(),
        new WooCommerceLoyaltyEconomicsReader(new WooCommerceEligiblePotCounter()),
        new WordPressClock(),
    );
    $findRow = static function (array $rows, string $needle) {
        foreach ($rows as $row) {
            if (str_contains($row->name, $needle)) {
                return $row;
            }
        }

        return null;
    };

    // --- Année en cours ---
    $view = $handler->handle(new GetLoyaltyDashboardQuery((string) $thisYear));
    $assert('L\'année affichée est l\'année en cours', (string) $thisYear === $view->periodKey);
    $assert('Les deux années sont proposées au sélecteur', in_array($thisYear, $view->availableYears, true) && in_array($lastYear, $view->availableYears, true));
    $alice = $findRow($view->customers, $nameA);
    $bob = $findRow($view->customers, $nameB);
    $assert('Alice a 5 pots achetés cette année (commande de l\'an dernier exclue)', 5 === ($alice->potsBought ?? 0));
    $assert('Alice a 1 pot offert et 2,50 € de remise cette année', 1 === ($alice->offeredPots ?? -1) && 250 === ($alice->discountCents ?? -1));
    $assert('Bob a 3 pots cette année', 3 === ($bob->potsBought ?? 0));
    $assert('Totaux année en cours : 8 pots, 1 offert, 250', 8 === $view->totalPots && 1 === $view->totalOfferedPots && 250 === $view->totalDiscountCents);
    $assert('Top meilleurs clients : Alice en tête', str_contains($view->topBuyers[0]->name ?? '', $nameA));

    // --- Année précédente : seule la commande d'Alice de l'an dernier compte ---
    $viewLast = $handler->handle(new GetLoyaltyDashboardQuery((string) $lastYear));
    $assert('L\'an dernier n\'a qu\'un client (Alice)', 1 === $viewLast->totalCustomers);
    $assert('L\'an dernier : Alice a 4 pots, 0 offert, 0 remise', 4 === $viewLast->totalPots && 0 === $viewLast->totalOfferedPots && 0 === $viewLast->totalDiscountCents);
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

WP_CLI::success(sprintf('%d assertions du tableau de fidélité (par année) validées ; données E2E supprimées.', count($results)));
