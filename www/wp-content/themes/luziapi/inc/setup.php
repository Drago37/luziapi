<?php

/**
 * Configuration du thème : supports, menus, styles & scripts.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('automatic-feed-links');
    add_theme_support('menus');

    // Support WooCommerce (galerie produit moderne).
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');

    register_nav_menus([
        'primary' => __('Menu principal', 'luziapi'),
        'footer'  => __('Menu pied de page', 'luziapi'),
    ]);

    // Format d'image dédié au hero.
    add_image_size('luziapi-hero', 1800, 1100, true);
});

/**
 * Styles & scripts du site (front).
 */
add_action('wp_enqueue_scripts', static function (): void {
    // Polices Google : Fraunces (titres) + Hanken Grotesk (texte).
    wp_enqueue_style(
        'luziapi-fonts',
        'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;0,9..144,800;0,9..144,900;1,9..144,500&family=Hanken+Grotesk:wght@400;500;600;700&display=swap',
        [],
        null
    );

    // Leaflet (carte OpenStreetMap, sans clé API).
    wp_enqueue_style(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        [],
        '1.9.4'
    );
    wp_enqueue_script(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        [],
        '1.9.4',
        true
    );

    // Feuille de style du thème.
    wp_enqueue_style(
        'luziapi-main',
        LUZIAPI_URI . '/assets/css/main.css',
        ['luziapi-fonts'],
        LUZIAPI_VERSION
    );

    // Scripts du thème (init carte, menu mobile…).
    wp_enqueue_script(
        'luziapi-main',
        LUZIAPI_URI . '/assets/js/main.js',
        ['leaflet'],
        LUZIAPI_VERSION,
        true
    );

    // Coordonnées du point de retrait transmises au JS (carte).
    wp_localize_script('luziapi-main', 'LUZIAPI_MAP', [
        'lat'   => 47.2861,
        'lng'   => 1.1206,
        'label' => 'LuziApi — 1 rue des Trois Cheminées, 37150 Luzillé',
    ]);
});
