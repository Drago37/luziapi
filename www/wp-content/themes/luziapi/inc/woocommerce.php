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
add_filter('loop_shop_columns', static fn (): int => 4);

// Badge « À venir » sur la vignette produit dans la boutique.
add_action('woocommerce_before_shop_loop_item_title', static function (): void {
    global $product;
    if ($product instanceof \WC_Product && luziapi_is_coming_soon($product)) {
        echo '<span class="wc-ribbon">' . esc_html__('À venir', 'luziapi') . '</span>';
    }
}, 5);

/**
 * Pot de miel dessiné (SVG) — affiché quand le produit n'a pas de photo,
 * pour rester cohérent avec l'accueil au lieu du placeholder gris de WooCommerce.
 */
function luziapi_jar_svg(string $fill, string $light, string $class = 'jar'): string
{
    $gid = 'wcjar' . preg_replace('/[^a-z0-9]/i', '', $fill);

    return '<svg viewBox="0 0 200 250" class="' . esc_attr($class) . '" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="' . esc_attr($light) . '"/><stop offset="1" stop-color="' . esc_attr($fill) . '"/></linearGradient></defs>'
        . '<rect x="56" y="32" width="88" height="26" rx="7" fill="#4a3018"/>'
        . '<rect x="52" y="52" width="96" height="13" rx="5" fill="#5e3c1d"/>'
        . '<path d="M50 70 h100 a14 14 0 0 1 14 14 v116 a18 18 0 0 1 -18 18 H54 a18 18 0 0 1 -18 -18 V84 a14 14 0 0 1 14 -14 z" fill="url(#' . $gid . ')" stroke="#e6d2a8" stroke-width="1"/>'
        . '<rect x="54" y="80" width="15" height="116" rx="7" fill="#ffffff" opacity="0.22"/>'
        . '<rect x="58" y="118" width="84" height="64" rx="7" fill="#fbf1da" stroke="#e6d2a8"/>'
        . '<polygon points="100,127 110,132 110,142 100,147 90,142 90,132" fill="#e0a124"/>'
        . '<text x="100" y="168" text-anchor="middle" font-family="Fraunces,serif" font-weight="800" font-size="16" fill="#4a3018">LuziApi</text>'
        . '</svg>';
}

function luziapi_product_jar(\WC_Product $product, string $class = 'jar'): string
{
    $colors = luziapi_jar_colors($product->get_slug());

    return luziapi_jar_svg($colors[0], $colors[1], $class);
}

// Vignette boutique : photo si dispo, sinon le pot dessiné (au lieu du placeholder).
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
add_action('woocommerce_before_shop_loop_item_title', static function (): void {
    global $product;
    if (! $product instanceof \WC_Product) {
        return;
    }
    if ($product->get_image_id()) {
        echo $product->get_image('woocommerce_thumbnail');
    } else {
        echo '<span class="wc-jar">' . luziapi_product_jar($product) . '</span>';
    }
}, 10);

// Image de la fiche produit : photo si dispo, sinon le pot dessiné.
remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);
add_action('woocommerce_before_single_product_summary', static function (): void {
    global $product;
    if (! $product instanceof \WC_Product) {
        return;
    }
    if ($product->get_image_id()) {
        woocommerce_show_product_images();
    } else {
        echo '<div class="woocommerce-product-gallery woocommerce-product-gallery--columns-1"><span class="wc-jar wc-jar--single">'
            . luziapi_product_jar($product)
            . '</span></div>';
    }
}, 20);

// Mise à jour AJAX du compteur de panier et du mini-panier (header), sans rechargement.
add_filter('woocommerce_add_to_cart_fragments', static function (array $fragments): array {
    $count = (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;

    $fragments['span.cart-count'] = '<span class="cart-count' . ($count > 0 ? '' : ' is-empty') . '">' . esc_html((string) $count) . '</span>';

    ob_start();
    woocommerce_mini_cart();
    $fragments['div.cart-dropdown__body'] = '<div class="cart-dropdown__body">' . ob_get_clean() . '</div>';

    return $fragments;
});

// Produits similaires : afficher les autres miels même sans catégorie commune.
add_filter('woocommerce_related_products', static function (array $related, int $product_id): array {
    if (empty($related)) {
        $related = wc_get_products([
            'status'  => 'publish',
            'limit'   => 4,
            'exclude' => [$product_id],
            'return'  => 'ids',
            'orderby' => 'menu_order',
            'order'   => 'ASC',
        ]);
    }

    return $related;
}, 10, 2);

// Titre + format de la section « produits similaires ».
add_filter('woocommerce_product_related_products_heading', static fn (): string => 'Nos autres miels');
add_filter('woocommerce_output_related_products_args', static function (array $args): array {
    $args['posts_per_page'] = 4;
    $args['columns']        = 4;

    return $args;
});

// Le client n'a pas à voir le stock disponible : on masque l'affichage du stock.
add_filter('woocommerce_get_stock_html', '__return_empty_string');

// Retire le bloc « méta » de la fiche (catégorie / SKU / étiquettes) : inutile ici.
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
