<?php

/**
 * Inspecteur LECTURE SEULE d'une commande côté fidélité, sur la PRODUCTION (script à
 * jeton, usage unique). Déposé à la racine du thème sous `_loyalty-inspect.php` par
 * `scripts/loyalty-inspect-prod.sh`, appelé en HTTPS avec le jeton, puis supprimé.
 *
 * Ne fait AUCUNE écriture : lit une commande, ses lignes (métas offert / fidélité /
 * produit admissible), les compteurs calculés et les écritures du journal fidélité
 * rattachées à la commande. Sert à diagnostiquer un comptage fidélité suspect.
 *
 * Sortie : JSON.
 */

declare(strict_types=1);

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

if (! function_exists('wc_get_order')) {
    echo json_encode(['error' => 'WooCommerce inactif']);
    exit;
}

$orderId = isset($_GET['order']) ? (int) $_GET['order'] : 0;
$order = $orderId > 0 ? wc_get_order($orderId) : null;
if (! $order instanceof WC_Order) {
    echo json_encode(['error' => "Commande #{$orderId} introuvable"]);
    exit;
}

$counter = new \LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter();
$ELIG = \LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META;
$OFFERT = \LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter::OFFERT_LINE_META;
$REWARD = \LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter::REWARD_LINE_META;

$lines = [];
foreach ($order->get_items() as $itemId => $item) {
    if (! $item instanceof WC_Order_Item_Product) {
        continue;
    }
    $product = $item->get_product();
    $admissible = $product instanceof WC_Product
        && ('yes' === $product->get_meta($ELIG, true)
            || ($product->get_parent_id() > 0
                && ($parent = wc_get_product($product->get_parent_id())) instanceof WC_Product
                && 'yes' === $parent->get_meta($ELIG, true)));
    $lines[] = [
        'name'        => $item->get_name(),
        'qty'         => (int) $item->get_quantity(),
        'refunded'    => (int) $order->get_qty_refunded_for_item((int) $itemId),
        'offert'      => (string) $item->get_meta($OFFERT),
        'reward'      => (string) $item->get_meta($REWARD),
        'admissible'  => $admissible ? 'yes' : 'no',
        'offert_label' => (string) $item->get_meta('Offert'),
    ];
}

global $wpdb;
$table = $wpdb->prefix . 'luziapi_loyalty_ledger';
$ledger = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} WHERE source_order_id = %d OR usage_order_id = %d OR reversal_of_id IN (SELECT id FROM {$table} WHERE source_order_id = %d) ORDER BY id ASC",
        $orderId,
        $orderId,
        $orderId
    ),
    ARRAY_A
);

echo json_encode([
    'order'  => $orderId,
    'status' => $order->get_status(),
    'email'  => $order->get_billing_email(),
    'phone'  => $order->get_billing_phone(),
    'excluded_from_loyalty' => (string) $order->get_meta('_luziapi_loyalty_excluded'),
    'lines'  => $lines,
    'computed' => [
        'eligible_pots' => $counter->countEligiblePots($order),
        'offered_pots'  => $counter->countOfferedPots($order),
        'reward_pots'   => $counter->countRewardPots($order),
    ],
    'ledger' => is_array($ledger) ? $ledger : [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
