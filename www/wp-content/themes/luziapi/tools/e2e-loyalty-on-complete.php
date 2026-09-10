<?php

/**
 * Test d'intégration local du moteur de fidélité (réconciliation).
 *
 * Exécution : make e2e-loyalty-local
 *
 * Utilise le vrai stockage WordPress, de vraies commandes WooCommerce (HPOS
 * compris) et les adaptateurs du thème. Vérifie la chaîne complète par
 * réconciliation : crédit à « Terminée », exclusion des offerts, décompte du
 * stock, consommation d'avantage, **remboursement partiel**, annulation, et
 * convergence. Aucune donnée n'est laissée en base, même en cas d'échec.
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

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de fidélité.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$clock = new WordPressClock();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$counter = new WooCommerceEligiblePotCounter();
$query = new GetCustomerLoyaltyHandler($ledger);

$subscriber = new WooCommerceLoyaltyEarningSubscriber(
    new ReconcileOrderLoyaltyHandler($ledger, $clock, new WordPressIdGenerator()),
    $counter,
    new WooCommerceOrderIdentityResolver(),
    new \Psr\Log\NullLogger(),
);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productId = 0;
$orderId = 0;
$customerKey = '';
$testSuffix = strtolower(wp_generate_password(10, false, false));
$customerEmail = 'fidelite-' . $testSuffix . '@example.test';
$customerPhone = '0600000000';

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->set_manage_stock(true);
    $pot->set_stock_quantity(20);
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $other = new WC_Product_Simple();
    $other->set_name('E2E — Coffret hors fidélité (ne pas commander)');
    $other->set_status('publish');
    $other->set_catalog_visibility('hidden');
    $other->set_regular_price('30');
    $other->set_price('30');
    $other->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'no');
    $otherId = (int) $other->save();
    $productIds = [$productId, $otherId];

    $order = wc_create_order(['status' => 'pending']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name('Client');
    $order->set_billing_email($customerEmail);
    $order->set_billing_phone($customerPhone);
    $order->set_payment_method('cod');
    $potItemId = $order->add_product(wc_get_product($productId), 3);   // 3 pots payés
    $order->add_product(wc_get_product($otherId), 5);                  // hors programme
    $giftItem = new WC_Order_Item_Product();
    $giftItem->set_product(wc_get_product($productId));
    $giftItem->set_quantity(1);
    $giftItem->set_subtotal('0');
    $giftItem->set_total('0');
    $giftItem->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $order->add_item($giftItem);
    $rewardItem = new WC_Order_Item_Product();
    $rewardItem->set_product(wc_get_product($productId));
    $rewardItem->set_quantity(1);
    $rewardItem->set_subtotal('0');
    $rewardItem->set_total('0');
    $rewardItem->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $rewardItem->add_meta_data(WooCommerceEligiblePotCounter::REWARD_LINE_META, 'yes', true);
    $order->add_item($rewardItem);
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $potItemId = 0;
    foreach ($order->get_items() as $itemId => $item) {
        if ($item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $productId
            && 'yes' !== (string) $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META)) {
            $potItemId = (int) $itemId;
            break;
        }
    }

    $customerKey = LoyaltyIdentity::fromContact($customerEmail, $customerPhone)?->key ?? '';
    $assert('L\'identité fidélité de la commande est résolue', '' !== $customerKey);
    $assert('Le compteur ne retient que les pots payés (offerts exclus)', 3 === $counter->countEligiblePots($order));
    $assert('Le compteur des pots offerts fidélité vaut 1', 1 === $counter->countRewardPots($order));

    wc_reduce_stock_levels($orderId);
    $assert('Le stock est décompté des pots offerts aussi (20 → 15)', 15 === (int) wc_get_product($productId)->get_stock_quantity());

    // Passage « Terminée » : réconciliation (crédit + consommation).
    $order->update_status('completed');
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $totals = $ledger->orderTotals($orderId);
    $assert('Réconciliation à « Terminée » : 3 pots crédités', 3 === $totals['pots']);
    $assert('Réconciliation à « Terminée » : 1 avantage consommé', -1 === $totals['rights']);

    // Idempotence / convergence : rejouer ne double rien.
    $entriesBefore = $ledger->totalsForCustomerKeys([$customerKey])['entryCount'];
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $assert('La réconciliation est convergente (aucune écriture en trop)', $entriesBefore === $ledger->totalsForCustomerKeys([$customerKey])['entryCount']);

    $view = $query->handle(new GetCustomerLoyaltyQuery([$customerKey]));
    $assert('La fiche voit 3 pots et 0 avantage disponible', 3 === $view->netPots && 0 === $view->rewardsAvailable);

    $publicView = (new GetLoyaltyForOrdersHandler(new WooCommerceOrderContactKeys(), $query))->handle([$orderId]);
    $assert('Le suivi client voit la fidélité depuis la commande', 3 === $publicView->netPots);
    if (function_exists('luziapi_email_loyalty_summary')) {
        $emailLoyalty = luziapi_email_loyalty_summary(wc_get_order($orderId));
        $assert('L\'e-mail « Terminée » verrait 3 pots', null !== $emailLoyalty && 3 === ($emailLoyalty['net_pots'] ?? 0));
    }

    // Remboursement partiel : 1 pot payé remboursé → cible 2 pots.
    wc_create_refund([
        'order_id'   => $orderId,
        'amount'     => 12,
        'line_items' => [$potItemId => ['qty' => 1, 'refund_total' => 12]],
    ]);
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $assert('Le compteur net après remboursement partiel vaut 2', 2 === $counter->countEligiblePots(wc_get_order($orderId)));
    $assert('Réconciliation du remboursement partiel : 2 pots', 2 === $ledger->orderTotals($orderId)['pots']);

    // Annulation : tout revient à zéro (pots et avantage).
    $order = wc_get_order($orderId);
    $order->update_status('cancelled');
    $subscriber->reconcile($orderId, wc_get_order($orderId));
    $final = $ledger->orderTotals($orderId);
    $assert('Annulation : les pots reviennent à zéro', 0 === $final['pots']);
    $assert('Annulation : l\'avantage est rendu', 0 === $final['rights']);
    $assert('Le total net du client revient à zéro', 0 === $ledger->totalsForCustomerKeys([$customerKey])['pots']);
} catch (Throwable $exception) {
    $assert('Le scénario d\'intégration se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
    }
    if ($orderId > 0) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    foreach ($productIds ?? [] as $pid) {
        wp_delete_post($pid, true);
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

WP_CLI::success(sprintf('%d assertions de fidélité validées ; données E2E supprimées.', count($results)));
