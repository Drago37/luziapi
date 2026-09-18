<?php

/**
 * Test d'intégration local de la REMISE DE VOLUME côté PANIER DU SITE (chemin client
 * réel) : le fee `woocommerce_cart_calculate_fees` d'`inc/shop.php`, via la règle
 * partagée `VolumeDiscount`.
 *
 * Exécution : make e2e-cart-volume-local
 *
 * Charge un vrai panier WooCommerce, y met DEUX miels différents (1 + 1) et vérifie
 * que la remise −2 € est appliquée ; un seul pot ne déclenche aucune remise. Le panier
 * est vidé et les produits supprimés en fin de test.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_get_product') || ! function_exists('wc_load_cart')) {
    WP_CLI::error('WooCommerce (panier) doit être actif pour lancer le test de remise volume panier.');
}

wc_load_cart();
$cart = WC()->cart;
if (! $cart instanceof WC_Cart) {
    WP_CLI::error('Le panier WooCommerce n\'a pas pu être initialisé.');
}

$cents = static fn ($value): int => (int) round((float) $value * 100);
$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$volumeFeeCents = static function (WC_Cart $cart) use ($cents): int {
    $sum = 0;
    foreach ($cart->get_fees() as $fee) {
        if (str_contains((string) $fee->name, 'par pot')) {
            $sum += $cents($fee->total);
        }
    }

    return $sum;
};

$productIds = [];

try {
    $makeProduct = static function (string $name, string $price) use (&$productIds): int {
        $p = new WC_Product_Simple();
        $p->set_name($name);
        $p->set_status('publish');
        $p->set_regular_price($price);
        $p->set_price($price);
        $p->set_manage_stock(true);
        $p->set_stock_quantity(50);
        $id = (int) $p->save();
        $productIds[] = $id;

        return $id;
    };
    $printemps = $makeProduct('E2E — Miel Printemps panier (ne pas commander)', '10');
    $tournesol = $makeProduct('E2E — Miel Tournesol panier (ne pas commander)', '11');

    // Deux miels différents (1 + 1) => 2 pots => remise −2 €.
    $cart->empty_cart();
    $assert('Panier : le 1er miel est ajouté', '' !== (string) $cart->add_to_cart($printemps, 1));
    $assert('Panier : le 2ᵉ miel est ajouté', '' !== (string) $cart->add_to_cart($tournesol, 1));
    $cart->calculate_totals();
    $assert('Panier : 2 pots au total', 2 === $cart->get_cart_contents_count());
    $assert('Panier multi-miels : remise de volume −2 €', -200 === $volumeFeeCents($cart), 'fee=' . $volumeFeeCents($cart));

    // Un seul pot => aucune remise.
    $cart->empty_cart();
    $cart->add_to_cart($printemps, 1);
    $cart->calculate_totals();
    $assert('Panier un seul pot : aucune remise de volume', 0 === $volumeFeeCents($cart), 'fee=' . $volumeFeeCents($cart));
} catch (Throwable $exception) {
    $assert('Le scénario de remise volume panier se termine sans exception', false, $exception->getMessage());
} finally {
    $cart->empty_cart();
    foreach ($productIds as $pid) {
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

WP_CLI::success(sprintf('%d assertions de remise volume (panier site) validées ; panier vidé.', count($results)));
