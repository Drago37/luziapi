<?php

/**
 * Test e2e de la recette automatique au passage « Terminée » sur la PRODUCTION
 * (script à jeton, à usage unique). Déposé sous `_e2e-receipt.php` par
 * `scripts/e2e-receipt-prod.sh`, appelé en HTTPS avec le jeton, puis supprimé.
 *
 * SÛR pour la prod : la commande de test est passée « Terminée » via `set_status`
 * (donc AUCUN e-mail, AUCUN mouvement de stock, AUCUNE fidélité déclenchée par le
 * hook) ; on invoque directement le subscriber recette avec le dépôt **non audité**
 * (donc AUCUNE écriture au journal d'activité) ; produit + commande + lignes de
 * recette créés sont supprimés en fin de run. Vérifie aussi le câblage du hook.
 *
 * Sortie : JSON { mode, all_passed, summary, results, cleanup, fatal_error }.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceReceiptSubscriber;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

add_filter('pre_wp_mail', '__return_false', 999);

$results = [];
$assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};
$fatal = null;
$cleanup = 'non exécuté';

if (! function_exists('wc_create_order')) {
    echo json_encode(['all_passed' => false, 'fatal_error' => 'WooCommerce inactif', 'results' => []]);
    exit;
}

global $wpdb;
$clock = new WordPressClock();
$schema = new PilotageSchemaManager($wpdb);
// Dépôt NON audité : n'écrit que la table des recettes (aucun journal d'activité).
$receipts = new WordPressReceiptRepository($wpdb, $schema, $clock->timezone());
$subscriber = new WooCommerceReceiptSubscriber(
    new RecordOrderReceiptHandler($receipts, new RecordReceiptHandler($receipts, $clock)),
    $clock,
);
$net = static fn (int $orderId): int => $receipts->netTotalsByOrderIds([$orderId])[$orderId] ?? 0;

$productId = 0;
$orderIds = [];

try {
    $product = new WC_Product_Simple();
    $product->set_name('E2E PROD — recette (test, à supprimer)');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price('12');
    $product->set_price('12');
    $productId = (int) $product->save();

    $makeOrder = static function (bool $quickSale) use ($productId, &$orderIds): WC_Order {
        $order = wc_create_order(['status' => 'pending']);
        $order->add_product(wc_get_product($productId), 1);
        $order->set_payment_method('bacs');
        $order->set_date_paid(time());
        if ($quickSale) {
            $order->update_meta_data('_luziapi_quick_sale', 'yes');
        }
        $order->calculate_totals();
        $order->set_status('completed'); // set_status : ne déclenche PAS les hooks.
        $order->save();
        $orderIds[] = (int) $order->get_id();

        return $order;
    };

    $assert(
        'Câblage : subscriber recette branché sur woocommerce_order_status_completed',
        false !== has_action('woocommerce_order_status_completed'),
    );

    // 1) Commande boutique « Terminée » → recette enregistrée pour le total.
    $shopOrder = $makeOrder(false);
    $shopId = (int) $shopOrder->get_id();
    $expected = (int) round((float) $shopOrder->get_total() * 100);
    $subscriber->orderCompleted($shopId, $shopOrder);
    $assert('Commande « Terminée » : recette enregistrée pour le total', $expected > 0 && $net($shopId) === $expected, 'attendu=' . $expected . ' obtenu=' . $net($shopId));

    // 2) Idempotence : ré-invoquer ne double pas.
    $subscriber->orderCompleted($shopId, wc_get_order($shopId));
    $assert('Idempotence : pas de doublon de recette', $net($shopId) === $expected);

    // 3) Commande issue de la Vente : pas ré-encaissée par ce hook.
    $venteOrder = $makeOrder(true);
    $venteId = (int) $venteOrder->get_id();
    $subscriber->orderCompleted($venteId, $venteOrder);
    $assert('Commande Vente : non ré-encaissée par le hook', 0 === $net($venteId));
} catch (\Throwable $exception) {
    $fatal = $exception->getMessage();
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
    $left = 0;
    foreach ($orderIds as $id) {
        $left += $net($id);
    }
    $cleanup = 0 === $left ? 'ok (recettes + commandes supprimées)' : ('reste ' . $left . ' en recette !');
    $assert('Nettoyage : aucune recette résiduelle', 0 === $left);
}

$failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $fatal,
    'summary'     => sprintf('%d/%d assertions', count($results) - $failed, count($results)),
    'results'     => $results,
    'cleanup'     => $cleanup,
    'fatal_error' => $fatal,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
