<?php

/**
 * Test d'intégration local des LIENS D'IDENTITÉ de fidélité.
 *
 * Exécution : make e2e-identity-links-local
 *
 * Sur les vraies classes et la vraie base :
 *  - AUTO-ALIMENTATION : deux commandes du même client avec un e-mail différent mais
 *    le MÊME téléphone (cas « 2ᵉ e-mail » / « changement de numéro ») sont reliées via
 *    le téléphone par le subscriber live ; la lecture de fidélité agrège alors les pots
 *    des deux, alors qu'une clé seule n'en verrait qu'une partie.
 *  - FUSION MANUELLE : deux clients sans aucun contact commun, fusionnés via le handler,
 *    voient ensuite leurs pots agrégés.
 * Nettoie toutes les données créées, même en cas d'échec.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesCommand;
use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyIdentityLinks;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test des liens d\'identité.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$links = new WordPressLoyaltyIdentityLinks($wpdb, $schema);
$linkedHandler = new GetCustomerLoyaltyHandler($ledger, $links);
$plainHandler = new GetCustomerLoyaltyHandler($ledger);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$netPots = static fn (GetCustomerLoyaltyHandler $handler, string $key): int => $handler
    ->handle(new GetCustomerLoyaltyQuery([$key]))->netPots;

$productId = 0;
$orderIds = [];
$allKeys = [];
$suffix = strtolower(wp_generate_password(10, false, false));

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot liens identité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    // Passe la commande à « Terminée » : le subscriber live crédite ET auto-alimente
    // les liens (e-mail + téléphone présents).
    $makeOrder = static function (string $email, string $phone, int $qty) use ($productId, &$orderIds): void {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Impossible de créer la commande E2E.');
        }
        $order->set_billing_first_name('Client');
        $order->set_billing_email($email);
        $order->set_billing_phone($phone);
        $order->set_payment_method('cod');
        $order->add_product(wc_get_product($productId), $qty);
        $order->calculate_totals();
        $order->save();
        $order->update_status('completed');
        $orderIds[] = (int) $order->get_id();
    };

    // === Auto-alimentation : 2ᵉ e-mail, même téléphone ===
    // Vrai mobile FR (chiffres uniquement) : sinon NormalizedPhone le rejette.
    $phone = '0699' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $emailA1 = 'link-a1-' . $suffix . '@example.test';
    $emailA2 = 'link-a2-' . $suffix . '@example.test';
    $makeOrder($emailA1, $phone, 4);
    $makeOrder($emailA2, $phone, 6);
    $keyA1 = LoyaltyIdentity::fromContact($emailA1, $phone)?->key ?? '';
    $keyA2 = LoyaltyIdentity::fromContact($emailA2, $phone)?->key ?? '';
    $keyPhone = LoyaltyIdentity::keysForContact('', $phone)[0] ?? '';
    $allKeys = array_merge($allKeys, [$keyA1, $keyA2, $keyPhone]);

    $assert('Auto : sans liens, une clé seule ne voit que ses 4 pots', 4 === $netPots($plainHandler, $keyA1));
    $assert('Auto : avec liens, le 1er e-mail agrège les 2 commandes (10 pots)', 10 === $netPots($linkedHandler, $keyA1), 'net=' . $netPots($linkedHandler, $keyA1));
    $assert('Auto : le 2ᵉ e-mail agrège aussi les 2 commandes (10 pots)', 10 === $netPots($linkedHandler, $keyA2));
    $assert('Auto : la clé téléphone agrège aussi (10 pots)', 10 === $netPots($linkedHandler, $keyPhone));

    // === Fusion manuelle : deux clients sans contact commun ===
    $emailB = 'link-b-' . $suffix . '@example.test';
    $emailC = 'link-c-' . $suffix . '@example.test';
    $makeOrder($emailB, '', 5);
    $makeOrder($emailC, '', 3);
    $keyB = LoyaltyIdentity::fromContact($emailB, '')?->key ?? '';
    $keyC = LoyaltyIdentity::fromContact($emailC, '')?->key ?? '';
    $allKeys = array_merge($allKeys, [$keyB, $keyC]);

    $assert('Fusion : avant fusion, B ne voit que ses 5 pots', 5 === $netPots($linkedHandler, $keyB));
    (new MergeLoyaltyIdentitiesHandler($links))->handle(new MergeLoyaltyIdentitiesCommand([$keyB], [$keyC]));
    $assert('Fusion : après fusion, B agrège B+C (8 pots)', 8 === $netPots($linkedHandler, $keyB), 'net=' . $netPots($linkedHandler, $keyB));
    $assert('Fusion : après fusion, C agrège aussi (8 pots)', 8 === $netPots($linkedHandler, $keyC));
} catch (Throwable $exception) {
    $assert('Le scénario des liens d\'identité se termine sans exception', false, $exception->getMessage());
} finally {
    $allKeys = array_values(array_unique(array_filter($allKeys, static fn (string $key): bool => '' !== $key)));
    foreach ($allKeys as $key) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $key], ['%s']);
        $wpdb->delete($schema->identityLinksTableName(), ['identity_key' => $key], ['%s']);
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

WP_CLI::success(sprintf('%d assertions des liens d\'identité validées ; données E2E supprimées.', count($results)));
