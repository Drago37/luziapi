<?php

/**
 * Test e2e de la FIDÉLITÉ sur la PRODUCTION (script à jeton, à usage unique).
 *
 * Déposé à la racine du thème sous `_e2e-loyalty.php` par
 * `scripts/e2e-loyalty-prod.sh`, appelé en HTTPS avec le jeton, puis supprimé.
 *
 * Sûr pour la prod : aucune commande n'est complétée par les hooks (statut posé
 * via set_status, donc AUCUN e-mail, AUCUNE recette, AUCUN mouvement de stock de
 * complétion), le produit de test est masqué du catalogue, et la commande, le
 * produit et les lignes de journal créés sont supprimés en fin de run. Ne touche
 * qu'un client isolé (e-mail aléatoire), donc n'altère aucune donnée réelle.
 *
 * Sortie : JSON { all_passed, summary, results:[{label,ok,detail}], cleanup,
 * fatal_error }.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Application\Query\GetLoyaltyForOrders\GetLoyaltyForOrdersHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceLoyaltyEarningSubscriber;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderContactKeys;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressClock;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressIdGenerator;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

// Ceinture : aucun e-mail ne doit partir pendant le test.
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
$schema = new LoyaltySchemaManager($wpdb);
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$counter = new WooCommerceEligiblePotCounter();
$query = new GetCustomerLoyaltyHandler($ledger, new WordPressClock());
$subscriber = new WooCommerceLoyaltyEarningSubscriber(
    new ReconcileOrderLoyaltyHandler($ledger, new WordPressClock(), new WordPressIdGenerator()),
    $counter,
    new WooCommerceOrderIdentityResolver(),
    new \Psr\Log\NullLogger(),
);

$productId = 0;
$orderId = 0;
$key = '';
$email = 'e2e-prod-' . bin2hex(random_bytes(5)) . '@example.test';

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E PROD — pot fidélité (test, à supprimer)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->set_manage_stock(true);
    $pot->set_stock_quantity(50);
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $order = wc_create_order(['status' => 'pending']);
    $order->set_billing_first_name('E2E');
    $order->set_billing_email($email);
    $order->set_billing_phone('0600000000');
    $order->add_product(wc_get_product($productId), 3);
    $gift = new WC_Order_Item_Product();
    $gift->set_product(wc_get_product($productId));
    $gift->set_quantity(1);
    $gift->set_subtotal('0');
    $gift->set_total('0');
    $gift->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $order->add_item($gift);
    $reward = new WC_Order_Item_Product();
    $reward->set_product(wc_get_product($productId));
    $reward->set_quantity(1);
    $reward->set_subtotal('0');
    $reward->set_total('0');
    $reward->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $reward->add_meta_data(WooCommerceEligiblePotCounter::REWARD_LINE_META, 'yes', true);
    $order->add_item($reward);
    $order->calculate_totals();
    $order->set_status('completed'); // set_status : ne déclenche PAS les hooks.
    $order->save();
    $orderId = (int) $order->get_id();
    $key = LoyaltyIdentity::fromContact($email, '0600000000')?->key ?? '';

    $potItemId = 0;
    foreach ($order->get_items() as $itemId => $item) {
        if ($item instanceof WC_Order_Item_Product
            && (int) $item->get_product_id() === $productId
            && 'yes' !== (string) $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META)) {
            $potItemId = (int) $itemId;
            break;
        }
    }

    $assert('Identité fidélité résolue', '' !== $key);
    $assert('Compteur : 3 pots payés (offerts exclus)', 3 === $counter->countEligiblePots($order));
    $assert('Compteur : 1 pot offert fidélité', 1 === $counter->countRewardPots($order));

    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $totals = $ledger->orderTotals($orderId);
    $assert('Réconciliation « Terminée » : 3 pots crédités', 3 === $totals['pots']);
    $assert('Réconciliation « Terminée » : 1 avantage consommé', -1 === $totals['rights']);

    $view = $query->handle(new GetCustomerLoyaltyQuery([$key]));
    $assert('Lecture fiche client : 3 pots (expiration active)', 3 === $view->netPots);
    $publicView = (new GetLoyaltyForOrdersHandler(new WooCommerceOrderContactKeys(), $query))->handle([$orderId]);
    $assert('Lecture suivi client (sans compte) : 3 pots', 3 === $publicView->netPots);

    wc_create_refund([
        'order_id'   => $orderId,
        'amount'     => 12,
        'line_items' => [$potItemId => ['qty' => 1, 'refund_total' => 12]],
    ]);
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $assert('Remboursement partiel : compteur net = 2', 2 === $counter->countEligiblePots(wc_get_order($orderId)));
    $assert('Remboursement partiel : réconciliation = 2 pots', 2 === $ledger->orderTotals($orderId)['pots']);

    $order = wc_get_order($orderId);
    $order->set_status('cancelled');
    $order->save();
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $final = $ledger->orderTotals($orderId);
    $assert('Annulation : pots à zéro', 0 === $final['pots']);
    $assert('Annulation : avantage rendu', 0 === $final['rights']);
} catch (Throwable $exception) {
    $fatal = $exception->getMessage();
} finally {
    if ('' !== $key) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $key], ['%s']);
    }
    if ($orderId > 0) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
    $left = '' !== $key
        ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $schema->ledgerTableName() . ' WHERE customer_key = %s', $key))
        : 0;
    $cleanup = 0 === $left ? 'ok (aucune ligne résiduelle)' : ($left . ' ligne(s) résiduelle(s) !');
    $assert('Nettoyage : aucune ligne de journal résiduelle', 0 === $left);
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
