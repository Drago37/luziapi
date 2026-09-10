<?php

/**
 * Test d'intégration local de la remise remerciement (lot 3).
 *
 * Exécution : make e2e-discount-local
 *
 * Vérifie, contre de vraies commandes WooCommerce, que la remise est portée
 * comme une VRAIE réduction (discount_total natif, pas de frais négatifs), pour
 * les deux chemins : à la création (helper partagé) et a posteriori (adaptateur).
 * La correction de recette a posteriori est couverte par le test unitaire
 * ApplyThankYouDiscountHandlerTest. Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderDiscountWriter;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceThankYouDiscount;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de remise.');
}

add_filter('pre_wp_mail', '__return_false', 999);

// Force 2 décimales le temps du test (assertions déterministes, comme la prod).
$previousDecimals = get_option('woocommerce_price_num_decimals');
update_option('woocommerce_price_num_decimals', '2');

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};
$cents = static fn ($value): int => (int) round((float) $value * 100);

$createdOrderIds = [];
$productId = 0;

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot remise (ne pas commander)');
    $pot->set_status('draft');
    $pot->set_regular_price('12');
    $productId = (int) $pot->save();

    $makeOrder = static function (int $productId): WC_Order {
        $order = wc_create_order(['status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Impossible de créer la commande E2E.');
        }
        $order->set_billing_email('remise@example.test');
        $order->add_product(wc_get_product($productId), 2); // 24,00 €
        $order->calculate_totals();
        $order->save();

        return $order;
    };

    // Chemin 1 — à la création : le helper applique la remise AVANT le premier
    // calcul des totaux, exactement comme la Vente (createNew).
    $order1 = wc_create_order(['status' => 'pending']);
    if (! $order1 instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order1->set_billing_email('remise@example.test');
    $order1->add_product(wc_get_product($productId), 2); // 24,00 €
    $applied = WooCommerceThankYouDiscount::applyTo($order1, ThankYouDiscount::percent(10));
    $order1->calculate_totals();
    $order1->save();
    $createdOrderIds[] = $order1->get_id();
    $reloaded1 = wc_get_order($order1->get_id());
    $assert('Création : la remise calculée vaut 10 % de 24 € (2,40 €)', 240 === $applied);
    $assert('Création : le total encaissé passe à 21,60 €', 2_160 === $cents($reloaded1->get_total()));
    $assert('Création : la remise apparaît en discount_total (vraie réduction)', 240 === $cents($reloaded1->get_discount_total()));
    $assert('Création : le sous-total reste à 24,00 € (pas de frais négatifs)', 2_400 === $cents($reloaded1->get_subtotal()));

    // Chemin 2 — a posteriori : l'adaptateur applique la remise sur une commande.
    $order2 = $makeOrder($productId);
    $createdOrderIds[] = $order2->get_id();
    $result = (new WooCommerceOrderDiscountWriter())->apply($order2->get_id(), ThankYouDiscount::amount(500));
    $assert('A posteriori : la remise de 5,00 € est appliquée', 500 === $result->discountCents);
    $reloaded = wc_get_order($order2->get_id());
    $assert('A posteriori : le total passe à 19,00 €', 1_900 === $cents($reloaded->get_total()));
    $assert('A posteriori : la remise apparaît en discount_total', 500 === $cents($reloaded->get_discount_total()));

    // Garde-fou : une remise supérieure au total est bornée au total.
    $order3 = $makeOrder($productId);
    $createdOrderIds[] = $order3->get_id();
    $capped = (new WooCommerceOrderDiscountWriter())->apply($order3->get_id(), ThankYouDiscount::amount(9_999));
    $assert('La remise est bornée au total de la commande', 2_400 === $capped->discountCents);
    $assert('Une commande 100 % remisée tombe à 0 €', 0 === $cents(wc_get_order($order3->get_id())->get_total()));
} catch (Throwable $exception) {
    $assert('Le scénario d\'intégration se termine sans exception', false, $exception->getMessage());
} finally {
    foreach ($createdOrderIds as $orderId) {
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

WP_CLI::success(sprintf('%d assertions de remise validées ; données E2E supprimées.', count($results)));
