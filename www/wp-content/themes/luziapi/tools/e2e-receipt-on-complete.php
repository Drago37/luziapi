<?php

/**
 * Test d'intégration : une commande boutique passée à « Terminée » enregistre
 * automatiquement sa recette (règle métier LuziApi), de façon idempotente, et
 * les commandes issues de la Vente ne sont pas ré-encaissées par ce hook.
 *
 * Exécution : make e2e-receipt-local. Aucun e-mail envoyé, données supprimées.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour ce test.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;
$clock = new WordPressClock();
$schema = new PilotageSchemaManager($wpdb);
$activity = new ActivityRecorder(new WordPressActivityRepository($wpdb, $schema, $clock->timezone()), $clock);
$receipts = new AuditedReceiptRepository(new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()), $activity);

$results = [];
$assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};
$net = static function (int $orderId) use ($receipts): int {
    return $receipts->netTotalsByOrderIds([$orderId])[$orderId] ?? 0;
};

$orderIds = [];
$productId = 0;

try {
    $product = new WC_Product_Simple();
    $product->set_name('E2E — Miel recette (ne pas commander)');
    $product->set_status('draft');
    $product->set_regular_price('12');
    $productId = (int) $product->save();

    $makeOrder = static function (int $productId, bool $quickSale = false) use (&$orderIds): WC_Order {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Commande E2E non créée.');
        }
        $order->add_product(wc_get_product($productId), 1);
        $order->set_payment_method('bacs');
        $order->set_date_paid(time());
        if ($quickSale) {
            $order->update_meta_data('_luziapi_quick_sale', 'yes');
        }
        $order->calculate_totals();
        $order->save();
        $orderIds[] = $order->get_id();

        return $order;
    };

    // 1) Commande boutique terminée => recette enregistrée pour le total.
    $shopOrder = $makeOrder($productId);
    $expected = (int) round((float) $shopOrder->get_total() * 100);
    $shopOrder->update_status('completed'); // déclenche le subscriber booté
    $assert('Une commande « Terminée » enregistre sa recette', $expected > 0 && $net($shopOrder->get_id()) === $expected);

    // 2) Idempotence : re-compléter ne double pas.
    $shopOrder->update_status('processing');
    $shopOrder->update_status('completed');
    $assert('Re-compléter ne crée pas de doublon', $net($shopOrder->get_id()) === $expected);

    // 3) Une commande issue de la Vente n'est pas ré-encaissée par ce hook.
    $venteOrder = $makeOrder($productId, true);
    $venteOrder->update_status('completed');
    $assert('Une commande Vente n’est pas ré-encaissée par le hook', 0 === $net($venteOrder->get_id()));
} catch (Throwable $exception) {
    $assert('Le scénario se termine sans exception', false, $exception->getMessage());
} finally {
    foreach ($orderIds as $id) {
        $wpdb->delete($schema->tableName(), ['order_id' => $id], ['%d']);
        $order = wc_get_order($id);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
}

$failed = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));
foreach ($results as $r) {
    WP_CLI::log(sprintf('%s %s%s', $r['ok'] ? '✓' : '✗', $r['label'], '' !== $r['detail'] ? ' — ' . $r['detail'] : ''));
}
if ([] !== $failed) {
    WP_CLI::error(sprintf('%d assertion(s) en échec sur %d.', count($failed), count($results)));
}
WP_CLI::success(sprintf('%d assertions recette-sur-terminée validées ; données supprimées.', count($results)));
