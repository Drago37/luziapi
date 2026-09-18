<?php

/**
 * Test d'intégration local des LIENS D'IDENTITÉ de fidélité (auto-liaison PRUDENTE,
 * fusion manuelle, défusion).
 *
 * Exécution : make e2e-identity-links-local
 *
 * Sur les vraies classes et la vraie base :
 *  - AUTO-LIAISON PRUDENTE : une commande e-mail+téléphone relie ces deux clés ; une
 *    commande téléphone-seul (même téléphone) crédite sous la clé téléphone ; la lecture
 *    agrège alors les deux — ce qu'une clé seule ne voit pas.
 *  - PRUDENCE : un 2ᵉ e-mail sur un téléphone DÉJÀ rattaché n'est PAS auto-fusionné.
 *  - FUSION MANUELLE : deux clients fusionnés à la main agrègent leur fidélité.
 *  - DÉFUSION : `unlink` détache un client d'un groupe fusionné à tort.
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

$net = static fn (GetCustomerLoyaltyHandler $handler, string $key): int => $handler
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

    // Passe la commande à « Terminée » : le subscriber live crédite ET auto-lie
    // (prudemment) e-mail↔téléphone quand les deux sont présents.
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

    // Vrais mobiles FR (chiffres uniquement).
    $phone = '0699' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $emailA = 'link-a-' . $suffix . '@example.test';
    $emailC = 'link-c-' . $suffix . '@example.test';
    $keyEmailA = LoyaltyIdentity::contactKeys($emailA, '')['email'] ?? '';
    $keyPhone = LoyaltyIdentity::contactKeys('', $phone)['phone'] ?? '';
    $keyEmailC = LoyaltyIdentity::contactKeys($emailC, '')['email'] ?? '';
    $allKeys = [$keyEmailA, $keyPhone, $keyEmailC];

    // === Auto-liaison : commande e-mail+téléphone, puis commande téléphone-seul ===
    $makeOrder($emailA, $phone, 4);   // crédité sous l'e-mail ; auto-lie e-mail↔téléphone
    $makeOrder('', $phone, 6);        // crédité sous le téléphone (pas d'e-mail → pas d'auto-lien)

    $assert('Auto : sans liens, l\'e-mail ne voit que ses 4 pots', 4 === $net($plainHandler, $keyEmailA));
    $assert('Auto : avec liens, l\'e-mail agrège la commande téléphone-seul (10)', 10 === $net($linkedHandler, $keyEmailA), 'net=' . $net($linkedHandler, $keyEmailA));
    $assert('Auto : la clé téléphone agrège aussi (10)', 10 === $net($linkedHandler, $keyPhone));

    // === Prudence : 2ᵉ e-mail sur un téléphone DÉJÀ rattaché → refusé ===
    $makeOrder($emailC, $phone, 3);   // auto-lien refusé (téléphone déjà pris)
    $assert('Prudence : le 2ᵉ e-mail n\'est PAS auto-fusionné (3 pots, pas 13)', 3 === $net($linkedHandler, $keyEmailC), 'net=' . $net($linkedHandler, $keyEmailC));

    // === Fusion manuelle : l'opérateur confirme que C est la même personne que A ===
    (new MergeLoyaltyIdentitiesHandler($links))->handle(new MergeLoyaltyIdentitiesCommand([$keyEmailC], [$keyEmailA]));
    $assert('Fusion : après fusion manuelle, C agrège tout le groupe (13)', 13 === $net($linkedHandler, $keyEmailC), 'net=' . $net($linkedHandler, $keyEmailC));

    // === Défusion : on détache C ; le reste du groupe (A + téléphone) reste groupé ===
    $links->unlink([$keyEmailC]);
    $assert('Défusion : C se sépare (3 pots)', 3 === $net($linkedHandler, $keyEmailC), 'net=' . $net($linkedHandler, $keyEmailC));
    $assert('Défusion : A + téléphone restent groupés (10 pots)', 10 === $net($linkedHandler, $keyEmailA), 'net=' . $net($linkedHandler, $keyEmailA));
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
