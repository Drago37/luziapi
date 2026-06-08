<?php

/**
 * Intégration WooCommerce.
 *
 * Les pages boutique/panier/commande utilisent les templates natifs de
 * WooCommerce, habillés par la feuille de style du thème. On remplace
 * seulement les conteneurs (wrappers) pour rester dans la mise en page du site.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// Retire les wrappers par défaut de WooCommerce.
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

// Ouvre/ferme notre propre conteneur autour du contenu WooCommerce.
add_action('woocommerce_before_main_content', static function (): void {
    echo '<div class="wrap wc-wrap"><section class="wc-section">';
}, 10);

add_action('woocommerce_after_main_content', static function (): void {
    echo '</section></div>';
}, 10);

// Nombre de produits par ligne dans la boutique.
add_filter('loop_shop_columns', static fn (): int => 3);

// Badge « À venir » sur la vignette produit dans la boutique.
add_action('woocommerce_before_shop_loop_item_title', static function (): void {
    global $product;
    if ($product instanceof \WC_Product && luziapi_is_coming_soon($product)) {
        echo '<span class="wc-ribbon">' . esc_html__('À venir', 'luziapi') . '</span>';
    }
}, 5);
