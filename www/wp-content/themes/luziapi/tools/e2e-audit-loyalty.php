<?php

/**
 * Test d'intégration local de l'AUDIT de dérive fidélité.
 *
 * Exécution : make e2e-audit-loyalty-local
 *
 * Sur les vraies classes et la vraie base (lecture seule pour l'audit lui-même) :
 * crée une commande admissible « Terminée » **non** créditée (journal vidé) → doit
 * apparaître en trou de crédit ; une commande admissible « Terminée » créditée par le
 * moteur live → ne doit PAS apparaître ; une commande créditée puis **supprimée** →
 * doit apparaître en crédit orphelin. Les assertions sont ciblées sur nos identifiants
 * (l'audit balaie toute la base). Rien n'est laissé en base, même en cas d'échec.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test d\'audit de fidélité.');
}

require_once __DIR__ . '/audit-loyalty-drift-core.php';

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
$orderIds = [];
$customerKey = '';
$testSuffix = strtolower(wp_generate_password(10, false, false));
$customerEmail = 'audit-' . $testSuffix . '@example.test';
// Téléphone unique par run (évite que autoLink rattache plusieurs runs entre eux).
$customerPhone = '06' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot audit (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $customerKey = LoyaltyIdentity::fromContact($customerEmail, $customerPhone)?->key ?? '';
    $assert('L\'identité fidélité de test est résolue', '' !== $customerKey);

    // Fabrique une commande « Terminée » avec $qty pots admissibles. Le passage à
    // « Terminée » déclenche le moteur live (crédit par réconciliation).
    $makeCompletedOrder = static function (int $qty) use ($productId, $customerEmail, $customerPhone): int {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Impossible de créer la commande E2E.');
        }
        $order->set_billing_first_name('Client');
        $order->set_billing_email($customerEmail);
        $order->set_billing_phone($customerPhone);
        $order->set_payment_method('cod');
        $order->add_product(wc_get_product($productId), $qty);
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();

        return (int) $order->get_id();
    };

    // (1) Trou de crédit : commande admissible dont on vide le journal (historique jamais vue).
    $gapOrderId = $makeCompletedOrder(4);
    $orderIds[] = $gapOrderId;
    $wpdb->delete($schema->ledgerTableName(), ['source_order_id' => $gapOrderId], ['%d']);
    $assert('Trou : la commande n\'a plus d\'écriture au journal', ! $ledger->hasEntryForOrder($gapOrderId));

    // (2) Commande saine : admissible et créditée par le moteur live.
    $healthyOrderId = $makeCompletedOrder(3);
    $orderIds[] = $healthyOrderId;
    $assert('Saine : la commande est créditée au journal', $ledger->hasEntryForOrder($healthyOrderId));

    // (3) Crédit orphelin : commande créditée puis supprimée.
    $orphanOrderId = $makeCompletedOrder(2);
    $assert('Orphelin : la commande est créditée avant suppression', $ledger->hasEntryForOrder($orphanOrderId));
    $orphanOrder = wc_get_order($orphanOrderId);
    if ($orphanOrder instanceof WC_Order) {
        $orphanOrder->delete(true);
    }
    $assert('Orphelin : la commande est bien supprimée', ! wc_get_order($orphanOrderId) instanceof WC_Order);

    // Audit (lecture seule, balaie toute la base).
    $data = luziapi_audit_loyalty_drift_data(null);
    $gapIds = array_column($data['gaps'], 'id');
    $orphanIds = array_column($data['orphans'], 'order_id');

    $assert('Audit : le trou de crédit est signalé', in_array($gapOrderId, $gapIds, true));
    $assert('Audit : la commande saine n\'est PAS signalée comme trou', ! in_array($healthyOrderId, $gapIds, true));
    $assert('Audit : le crédit orphelin est signalé', in_array($orphanOrderId, $orphanIds, true));

    foreach ($data['gaps'] as $gap) {
        if ($gap['id'] === $gapOrderId) {
            $assert('Audit : le trou porte les 4 pots admissibles', 4 === $gap['pots'], 'pots=' . $gap['pots']);
        }
    }
    foreach ($data['orphans'] as $orphan) {
        if ($orphan['order_id'] === $orphanOrderId) {
            $assert('Audit : l\'orphelin porte les 2 pots crédités', 2 === $orphan['pots'], 'pots=' . $orphan['pots']);
        }
    }

    $assert('Audit : une dérive est détectée', $data['has_drift']);
} catch (Throwable $exception) {
    $assert('Le scénario d\'audit se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
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

WP_CLI::success(sprintf('%d assertions d\'audit fidélité validées ; données E2E supprimées.', count($results)));
