<?php

/**
 * Test d'intégration local des surfaces client de la fidélité (lot 4) + de la
 * correction de recette de la remise a posteriori (lot 3).
 *
 * Exécution : make e2e-loyalty-client-local
 *
 * Sur une VRAIE commande passée « Terminée » (crédit des pots + recette
 * automatique) :
 *  - #1 : l'e-mail « Terminée » réellement rendu contient le bloc fidélité avec le
 *    bon compteur (rendu HTML du template, pas seulement la donnée) ;
 *  - #3 : appliquer une remise remerciement a posteriori via le handler réel
 *    (WordPressReceiptRepository) corrige la recette au registre.
 * Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountCommand;
use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderDiscountWriter;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

$previousDecimals = get_option('woocommerce_price_num_decimals');
update_option('woocommerce_price_num_decimals', '2');

global $wpdb;
$loyaltySchema = new LoyaltySchemaManager($wpdb);
$loyaltySchema->migrate();
$pilotageSchema = new PilotageSchemaManager($wpdb);
$pilotageSchema->migrate();
$timezone = wp_timezone();
$clock = new WordPressClock();
$receipts = new WordPressReceiptRepository($wpdb, $pilotageSchema, $timezone);
$discountHandler = new ApplyThankYouDiscountHandler(
    new WooCommerceOrderDiscountWriter(),
    new RecordReceiptHandler($receipts, $clock),
    $receipts,
    new ActivityRecorder(new WordPressActivityRepository($wpdb, $pilotageSchema, $timezone), $clock),
    $clock,
);
$cents = static fn ($value): int => (int) round((float) $value * 100);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productId = 0;
$orderId = 0;
$order2Id = 0;
$customerKey = '';
$suffix = strtolower(wp_generate_password(10, false, false));
$email = 'fidelite-client-' . $suffix . '@example.test';
$phone = '0600000000';

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot client fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $order = wc_create_order(['status' => 'pending']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name('Client');
    $order->set_billing_email($email);
    $order->set_billing_phone($phone);
    $order->set_payment_method('cod');
    $order->add_product(wc_get_product($productId), 3); // 3 pots = 36,00 €
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $customerKey = LoyaltyIdentity::fromContact($email, $phone)?->key ?? '';

    // Capture des e-mails déclenchés par la complétion (sans envoi réel).
    $mails = [];
    $capture = static function ($short, array $atts) use (&$mails): bool {
        $mails[] = $atts;

        return true;
    };
    add_filter('pre_wp_mail', $capture, 999, 2);
    // Passage « Terminée » : crédit fidélité (prio 5) AVANT l'e-mail (prio 10),
    // puis recette automatique (prio 100).
    $order->update_status('completed', 'E2E complétion.', true);
    remove_filter('pre_wp_mail', $capture, 999);

    // #1 — le bloc fidélité est rendu dans l'e-mail « Terminée ».
    $completedBody = '';
    foreach ($mails as $mail) {
        $body = (string) ($mail['message'] ?? '');
        if (str_contains($body, 'Fidélité LuziApi')) {
            $completedBody = $body;
            break;
        }
    }
    $assert('L\'e-mail « Terminée » contient le bloc fidélité', '' !== $completedBody);
    $assert('L\'e-mail affiche le compteur de pots à jour (3 pots)', str_contains($completedBody, '3 pot'));

    // #3 — la remise a posteriori corrige la recette au registre.
    $collectedBefore = $receipts->netTotalsByOrderIds([$orderId])[$orderId] ?? 0;
    $assert('La recette automatique de la commande est bien 36,00 €', 3_600 === $collectedBefore);

    $discountHandler->handle(new ApplyThankYouDiscountCommand($orderId, ThankYouDiscount::amount(500), 0));
    $reloaded = wc_get_order($orderId);
    $assert('Le total de la commande passe à 31,00 €', 3_100 === $cents($reloaded->get_total()));
    $collectedAfter = $receipts->netTotalsByOrderIds([$orderId])[$orderId] ?? 0;
    $assert('La recette est corrigée à 31,00 € (Refund de 5,00 €)', 3_100 === $collectedAfter);

    // #2 — la page de suivi : le handler branché du thème (celui injecté dans le
    // contrôleur de suivi) agrège la fidélité sur PLUSIEURS commandes du client.
    $order2 = wc_create_order(['status' => 'pending']);
    if ($order2 instanceof WC_Order) {
        $order2->set_billing_email($email);
        $order2->set_billing_phone($phone);
        $order2->add_product(wc_get_product($productId), 1);
        $order2->calculate_totals();
        $order2->save();
        $order2Id = (int) $order2->get_id();
    }
    $forOrders = \LuziApi\Loyalty\Bootstrap\LoyaltyServiceProvider::loyaltyForOrdersHandler();
    $assert('Le handler de suivi de la fidélité est branché', null !== $forOrders);
    if (null !== $forOrders) {
        $sessionView = $forOrders->handle(array_filter([$orderId, $order2Id]));
        $assert('Le suivi agrège 3 pots sur les commandes de la session', 3 === $sessionView->netPots);
    }
} catch (Throwable $exception) {
    $assert('Le scénario se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($loyaltySchema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
    }
    if ($orderId > 0) {
        $wpdb->delete($pilotageSchema->tableName(), ['order_id' => $orderId], ['%d']);
        $wpdb->delete($pilotageSchema->activityTableName(), ['object_id' => $orderId], ['%d']);
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if ($order2Id > 0) {
        $order2 = wc_get_order($order2Id);
        if ($order2 instanceof WC_Order) {
            $order2->delete(true);
        }
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
    update_option('woocommerce_price_num_decimals', false === $previousDecimals ? '2' : $previousDecimals);
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

WP_CLI::success(sprintf('%d assertions client (e-mail + recette) validées ; données E2E supprimées.', count($results)));
