<?php

/**
 * Test d'intégration local du CHEMIN ADMIN de fusion / défusion de clients fidélité.
 *
 * Exécution : make e2e-merge-admin-local
 *
 * Pilote le VRAI CustomersController (résolution `id de profil → identityIds` via le
 * répertoire, puis fusion/défusion) sur la vraie base — ce que l'e2e domaine
 * `e2e-identity-links` ne couvre pas (il teste le handler/port, pas le contrôleur). On
 * appelle `performMerge` / `performUnlink` (cœur sans redirection). Nettoie tout.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Shop\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Shop\Application\Query\GetCustomerDirectory\GetCustomerDirectoryQuery;
use LuziApi\Shop\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceCustomerTimelineRepository;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Shop\Infrastructure\WordPress\AuditedCustomerCategoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Shop\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Shop\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Shared\Infrastructure\WordPress\WordPressClock;
use LuziApi\Shop\Infrastructure\WordPress\WordPressCustomerCategoryRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressCustomerProfileRepository;
use LuziApi\Shop\Infrastructure\WordPress\WordPressReceiptRepository;
use LuziApi\Shop\UserInterface\Admin\CustomersController;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de fusion admin.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$loyaltySchema = new LoyaltySchemaManager($wpdb);
$loyaltySchema->migrate();
$loyaltyRead = LoyaltyServiceProvider::customerLoyaltyHandler();

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};
$net = static fn (string $key): int => null === $loyaltyRead
    ? -1
    : $loyaltyRead->handle(new GetCustomerLoyaltyQuery([$key]))->netPots;

// Reconstruit un CustomersController réel (dépendances du provider) pour piloter le
// vrai chemin admin sans passer par admin-post/redirect.
$clock = new WordPressClock();
$schema = new PilotageSchemaManager($wpdb);
$activity = new ActivityRecorder(new WordPressActivityRepository($wpdb, $schema, $clock->timezone()), $clock);
$categories = new AuditedCustomerCategoryRepository(new WordPressCustomerCategoryRepository($wpdb, $schema), $activity);
$directory = new GetCustomerDirectoryHandler(
    new WooCommerceOrderRepository($clock->timezone()),
    new CustomerHistoryProjector(),
    $categories,
    new AuditedReceiptRepository(new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()), $activity),
    new WooCommerceCustomerTimelineRepository($clock->timezone()),
    $clock,
    new WordPressCustomerProfileRepository($wpdb, $schema),
);
$controller = new CustomersController(
    getCustomers: $directory,
    assignCategory: new AssignCustomerCategoryHandler($categories, $clock),
    activity: $activity,
    mergeIdentities: LoyaltyServiceProvider::mergeIdentitiesHandler(),
    identityLinks: LoyaltyServiceProvider::identityLinks(),
);

$productId = 0;
$orderIds = [];
$suffix = strtolower(wp_generate_password(10, false, false));
$emailA = 'merge-a-' . $suffix . '@example.test';
$emailC = 'merge-c-' . $suffix . '@example.test';
$keyA = LoyaltyIdentity::contactKeys($emailA, '')['email'] ?? '';
$keyC = LoyaltyIdentity::contactKeys($emailC, '')['email'] ?? '';

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot fusion admin (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    // Deux clients distincts, sans téléphone (donc sans auto-lien) : 5 pots et 3 pots.
    $makeOrder = static function (string $email, int $qty) use ($productId, &$orderIds): void {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Impossible de créer la commande E2E.');
        }
        $order->set_billing_first_name('Client');
        $order->set_billing_email($email);
        $order->add_product(wc_get_product($productId), $qty);
        $order->calculate_totals();
        $order->save();
        $order->update_status('completed');
        $orderIds[] = (int) $order->get_id();
    };
    $makeOrder($emailA, 5);
    $makeOrder($emailC, 3);

    // Résout les identifiants de profil comme le fait le répertoire admin.
    $idA = '';
    $idC = '';
    foreach ($directory->handle(new GetCustomerDirectoryQuery('', 1, 200))->customers as $customer) {
        if (in_array($keyA, $customer->identityIds, true)) {
            $idA = $customer->id;
        }
        if (in_array($keyC, $customer->identityIds, true)) {
            $idC = $customer->id;
        }
    }
    $assert('Les deux clients de test sont retrouvés dans le répertoire', '' !== $idA && '' !== $idC);

    $assert('Avant fusion : A a ses 5 pots', 5 === $net($keyA), 'net=' . $net($keyA));

    // Requête invalide : auto-fusion sur soi-même refusée.
    $selfMergeRefused = false;
    try {
        $controller->performMerge($idA, $idA);
    } catch (Throwable) {
        $selfMergeRefused = true;
    }
    $assert('Chemin admin : fusion d\'un client avec lui-même refusée', $selfMergeRefused);

    // Fusion via le contrôleur (résolution id → identityIds + fusion).
    $controller->performMerge($idA, $idC);
    $assert('Chemin admin : après fusion, A agrège A+C (8)', 8 === $net($keyA), 'net=' . $net($keyA));
    $assert('Chemin admin : C agrège aussi (8)', 8 === $net($keyC), 'net=' . $net($keyC));

    // Défusion via le contrôleur.
    $controller->performUnlink($idC);
    $assert('Chemin admin : après défusion, C se sépare (3)', 3 === $net($keyC), 'net=' . $net($keyC));
    $assert('Chemin admin : A reste à 5', 5 === $net($keyA), 'net=' . $net($keyA));
} catch (Throwable $exception) {
    $assert('Le scénario de fusion admin se termine sans exception', false, $exception->getMessage());
} finally {
    foreach ([$keyA, $keyC] as $key) {
        if ('' !== $key) {
            $wpdb->delete($loyaltySchema->ledgerTableName(), ['customer_key' => $key], ['%s']);
            $wpdb->delete($loyaltySchema->identityLinksTableName(), ['identity_key' => $key], ['%s']);
        }
    }
    foreach ($orderIds as $orderId) {
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

WP_CLI::success(sprintf('%d assertions du chemin admin de fusion validées ; données E2E supprimées.', count($results)));
