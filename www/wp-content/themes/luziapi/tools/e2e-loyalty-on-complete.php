<?php

/**
 * Test d'intégration local du programme de fidélité (lot 1 : acquisition).
 *
 * Exécution : make e2e-loyalty-local
 *
 * Utilise le vrai stockage WordPress, de vraies commandes WooCommerce (HPOS
 * compris) et les adaptateurs du thème. Vérifie la chaîne complète :
 * compteur de pots éligibles → résolution d'identité → journal en base, avec
 * idempotence et contre-passation. Aucune donnée n'est laissée en base, même en
 * cas d'échec.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Command\RecordRewardConsumption\RecordRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Command\ReverseOrderCredit\ReverseOrderCreditHandler;
use LuziApi\Loyalty\Application\Command\ReverseRewardConsumption\ReverseRewardConsumptionHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Application\Query\GetLoyaltyForOrders\GetLoyaltyForOrdersHandler;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceLoyaltyEarningSubscriber;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderContactKeys;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressClock;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de fidélité.');
}

// Aucun e-mail ne doit quitter l'environnement local pendant le test.
add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$clock = new WordPressClock();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$counter = new WooCommerceEligiblePotCounter();
$resolver = new WooCommerceOrderIdentityResolver();
$query = new GetCustomerLoyaltyHandler($ledger);

// Le subscriber pilote la chaîne réelle (compteur + identité + journal).
$subscriber = new WooCommerceLoyaltyEarningSubscriber(
    new RecordCompletedOrderHandler($ledger, $clock),
    new ReverseOrderCreditHandler($ledger, $clock),
    new RecordRewardConsumptionHandler($ledger, $clock),
    new ReverseRewardConsumptionHandler($ledger, $clock),
    $counter,
    $resolver,
    new \Psr\Log\NullLogger(),
);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$createdOrderIds = [];
$productIds = [];
$customerKey = '';
$testSuffix = strtolower(wp_generate_password(10, false, false));
$customerEmail = 'fidelite-' . $testSuffix . '@example.test';
$customerPhone = '0600000000';

try {
    // Produit admissible (pot de miel), stock suivi pour vérifier le décompte.
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot fidélité (ne pas commander)');
    $pot->set_status('draft');
    $pot->set_regular_price('12');
    $pot->set_manage_stock(true);
    $pot->set_stock_quantity(20);
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $potId = (int) $pot->save();
    $productIds[] = $potId;

    $other = new WC_Product_Simple();
    $other->set_name('E2E — Coffret hors fidélité (ne pas commander)');
    $other->set_status('draft');
    $other->set_regular_price('30');
    $other->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'no');
    $otherId = (int) $other->save();
    $productIds[] = $otherId;

    $order = wc_create_order(['status' => 'pending']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name('Client');
    $order->set_billing_last_name('Fidélité E2E');
    $order->set_billing_email($customerEmail);
    $order->set_billing_phone($customerPhone);
    $order->set_payment_method('cod');
    $order->add_product(wc_get_product($potId), 3);   // 3 pots payés (éligibles)
    $order->add_product(wc_get_product($otherId), 5); // hors programme
    // 1 pot offert (geste commercial) : 0 €, marqué offert.
    $giftItem = new WC_Order_Item_Product();
    $giftItem->set_product(wc_get_product($potId));
    $giftItem->set_quantity(1);
    $giftItem->set_subtotal('0');
    $giftItem->set_total('0');
    $giftItem->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $order->add_item($giftItem);
    // 1 pot offert au titre de la fidélité : 0 €, marqué offert + fidélité.
    $rewardItem = new WC_Order_Item_Product();
    $rewardItem->set_product(wc_get_product($potId));
    $rewardItem->set_quantity(1);
    $rewardItem->set_subtotal('0');
    $rewardItem->set_total('0');
    $rewardItem->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $rewardItem->add_meta_data(WooCommerceEligiblePotCounter::REWARD_LINE_META, 'yes', true);
    $order->add_item($rewardItem);
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $createdOrderIds[] = $orderId;

    $customerKey = LoyaltyIdentity::fromContact($customerEmail, $customerPhone)?->key ?? '';
    $assert('L\'identité fidélité de la commande est résolue', '' !== $customerKey);
    $assert('Le compteur ne retient que les pots payés (offerts exclus)', 3 === $counter->countEligiblePots($order));
    $assert('Le compteur des pots offerts fidélité vaut 1', 1 === $counter->countRewardPots($order));

    // Décompte du stock (comme la Vente) : 3 payés + 1 offert + 1 fidélité = 5.
    wc_reduce_stock_levels($orderId);
    $remaining = wc_get_product($potId)->get_stock_quantity();
    $assert('Le stock est décompté des pots offerts aussi (20 → 15)', 15 === (int) $remaining);

    // Passage « Terminée » : pots crédités ET avantage consommé.
    $subscriber->orderCompleted($orderId, $order);
    $credit = $ledger->findByIdempotencyKey('credit:' . $orderId);
    $assert('Le crédit fidélité est journalisé au passage « Terminée »', null !== $credit);
    $assert('Le crédit porte exactement les pots payés', 3 === ($credit->potsDelta ?? 0));
    $assert('Le crédit est du bon type', LoyaltyEntryType::PurchaseCredited === ($credit->type ?? null));
    $assert('Le crédit est rattaché à la bonne identité', $customerKey === ($credit->customerKey ?? ''));

    $consumption = $ledger->findByIdempotencyKey('reward:' . $orderId);
    $assert('La consommation d\'avantage est journalisée', null !== $consumption);
    $assert('La consommation décompte un avantage', -1 === ($consumption->rightsDelta ?? 0));
    $assert('La consommation est du bon type', LoyaltyEntryType::RewardConsumed === ($consumption->type ?? null));

    // Idempotence : rejouer le hook ne double ni le crédit ni la consommation.
    $subscriber->orderCompleted($orderId, $order);
    $totalsAfter = $ledger->totalsForCustomerKeys([$customerKey]);
    $assert('Crédit et consommation idempotents', 3 === $totalsAfter['pots'] && 1 === $totalsAfter['rightsConsumed'] && 2 === $totalsAfter['entryCount']);

    // La fiche client agrège pots et avantages.
    $view = $query->handle(new GetCustomerLoyaltyQuery([$customerKey]));
    $assert('La fiche voit 3 pots et 0 avantage disponible', 3 === $view->netPots && 0 === $view->rewardsAvailable);
    $assert('La fiche liste crédit et consommation', 2 === count($view->entries));

    // Affichage côté client (page de suivi, sans compte) : l'identité se déduit
    // des commandes accessibles à la session, ici depuis la commande elle-même.
    $publicView = (new GetLoyaltyForOrdersHandler(new WooCommerceOrderContactKeys(), $query))->handle([$orderId]);
    $assert('Le suivi client voit la fidélité depuis la commande', 3 === $publicView->netPots);

    // Sortie de « Terminée » (annulation) : crédit ET avantage contre-passés.
    $subscriber->orderStatusChanged($orderId, 'completed', 'cancelled', $order);
    $reversal = $ledger->findByIdempotencyKey('reverse:' . $orderId);
    $restore = $ledger->findByIdempotencyKey('reward-reversal:' . $orderId);
    $assert('La contre-passation du crédit est journalisée', null !== $reversal && -3 === ($reversal->potsDelta ?? 0));
    $assert('La restitution de l\'avantage est journalisée', null !== $restore && 1 === ($restore->rightsDelta ?? 0));

    $netAfterReversal = $ledger->totalsForCustomerKeys([$customerKey]);
    $assert('Les totaux nets reviennent à zéro après annulation', 0 === $netAfterReversal['pots'] && 0 === $netAfterReversal['rightsConsumed']);

    // Idempotence de la contre-passation.
    $subscriber->orderStatusChanged($orderId, 'completed', 'cancelled', $order);
    $finalTotals = $ledger->totalsForCustomerKeys([$customerKey]);
    $assert('Les contre-passations sont idempotentes', 0 === $finalTotals['pots'] && 4 === $finalTotals['entryCount']);
} catch (Throwable $exception) {
    $assert('Le scénario d\'intégration se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
    }
    foreach ($createdOrderIds as $orderId) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    foreach ($productIds as $productId) {
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

WP_CLI::success(sprintf('%d assertions de fidélité validées ; données E2E supprimées.', count($results)));
