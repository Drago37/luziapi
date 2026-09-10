<?php

/**
 * Test d'intégration local du chemin RÉEL de la Vente avec fidélité et remise.
 *
 * Exécution : make e2e-vente-loyalty-local
 *
 * Contrairement à e2e-loyalty (qui construit les lignes à la main), ce test pilote
 * le vrai écrivain de la Vente `WooCommerceQuickSaleOrderWriter::create()` avec une
 * commande contenant : des pots payés, un pot offert (geste), un pot offert au
 * titre de la fidélité et une remise remerciement. Il vérifie la structure de la
 * commande créée (lignes marquées, 0 €, remise en discount_total), le décompte du
 * stock, puis — la commande passant « Terminée » — le crédit des pots et la
 * consommation de l'avantage écrits par le subscriber branché du thème.
 * Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLine;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

add_filter('pre_wp_mail', '__return_false', 999);
$previousDecimals = get_option('woocommerce_price_num_decimals');
update_option('woocommerce_price_num_decimals', '2');

global $wpdb;
$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$cents = static fn ($value): int => (int) round((float) $value * 100);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productId = 0;
$orderId = 0;
$customerKey = '';
$suffix = strtolower(wp_generate_password(10, false, false));
$email = 'vente-loyalty-' . $suffix . '@example.test';
$phone = '0600000000';

try {
    // L'écrivain de la Vente exige un produit achetable (is_purchasable) : publié
    // mais masqué du catalogue, et supprimé en fin de test.
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot Vente fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->set_manage_stock(true);
    $pot->set_stock_quantity(20);
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $command = new CreateQuickSaleCommand(
        [new QuickSaleLine($productId, 2)],            // 2 pots payés (24,00 €)
        'Client Vente E2E',
        $email,
        $phone,
        '',
        '',
        '',
        'market',
        'cash',
        'immediate',
        true,   // payée -> passe « Terminée »
        false,
        new DateTimeImmutable('now', wp_timezone()),
        0,
        wp_generate_uuid4(),
        [new QuickSaleLine($productId, 1)],           // 1 pot offert (geste)
        [new QuickSaleLine($productId, 1)],           // 1 pot offert (fidélité)
        ThankYouDiscount::percent(10),                // -10 % sur les pots payés
    );

    $created = (new WooCommerceQuickSaleOrderWriter())->create($command);
    $orderId = $created->orderId;
    $customerKey = LoyaltyIdentity::fromContact($email, $phone)?->key ?? '';

    $order = wc_get_order($orderId);
    $paid = 0;
    $gift = 0;
    $reward = 0;
    foreach ($order->get_items() as $item) {
        $lineCents = $cents($item->get_total());
        $isOffered = 'yes' === (string) $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META);
        $isReward = 'yes' === (string) $item->get_meta(WooCommerceEligiblePotCounter::REWARD_LINE_META);
        if ($isReward) {
            ++$reward;
            $assert('La ligne fidélité est à 0 € et marquée offerte', 0 === $lineCents && $isOffered);
        } elseif ($isOffered) {
            ++$gift;
            $assert('La ligne offerte (geste) est à 0 €', 0 === $lineCents);
        } else {
            ++$paid;
            $assert('La ligne payée porte la remise (21,60 € pour 2 pots)', 2_160 === $lineCents);
        }
    }
    $assert('La commande a bien 3 lignes (payée, offerte, fidélité)', 1 === $paid && 1 === $gift && 1 === $reward);
    $assert('Le total encaissé est remisé (21,60 €)', 2_160 === $created->totalCents);
    $assert('La remise apparaît en discount_total (2,40 €)', 240 === $cents($order->get_discount_total()));
    $assert('La commande est « Terminée »', 'completed' === $order->get_status());
    $assert('Le stock est décompté des 4 pots (20 → 16)', 16 === (int) wc_get_product($productId)->get_stock_quantity());

    // La commande étant passée « Terminée », le subscriber branché a crédité les
    // pots payés (offerts exclus) et consommé l'avantage.
    $orderTotals = $ledger->orderTotals($orderId);
    $assert('Le subscriber a crédité les 2 pots payés', 2 === $orderTotals['pots']);
    $assert('Le subscriber a consommé 1 avantage', -1 === $orderTotals['rights']);
} catch (Throwable $exception) {
    $assert('Le scénario se termine sans exception', false, $exception->getMessage());
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

WP_CLI::success(sprintf('%d assertions Vente+fidélité validées ; données E2E supprimées.', count($results)));
