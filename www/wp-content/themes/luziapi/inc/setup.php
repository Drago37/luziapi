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

    // Leaflet (carte OpenStreetMap) — enregistré, chargé uniquement sur l'accueil.
    wp_register_style(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        [],
        '1.9.4'
    );
    wp_register_script(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        [],
        '1.9.4',
        true
    );

    $main_deps = [];
    if (is_front_page() || is_page('recuperation-essaims')) {
        wp_enqueue_style('leaflet');
        wp_enqueue_script('leaflet');
        $main_deps[] = 'leaflet';
    }

    // Version basée sur la date de modification du fichier : casse le cache
    // (navigateur + o2switch) automatiquement à chaque mise à jour.
    $css_file = LUZIAPI_DIR . '/assets/css/main.css';
    $js_file  = LUZIAPI_DIR . '/assets/js/main.js';
    $css_ver  = (string) (@filemtime($css_file) ?: LUZIAPI_VERSION);
    $js_ver   = (string) (@filemtime($js_file) ?: LUZIAPI_VERSION);

    // Feuille de style du thème.
    wp_enqueue_style(
        'luziapi-main',
        LUZIAPI_URI . '/assets/css/main.css',
        ['luziapi-fonts'],
        $css_ver
    );

    // Scripts du thème (menu mobile partout ; init carte seulement si Leaflet présent).
    wp_enqueue_script(
        'luziapi-main',
        LUZIAPI_URI . '/assets/js/main.js',
        $main_deps,
        $js_ver,
        true
    );

    // Coordonnées du point de retrait transmises au JS (carte).
    wp_localize_script('luziapi-main', 'LUZIAPI_MAP', [
        'lat'    => 47.2861,
        'lng'    => 1.1206,
        'label'  => 'LuziApi — 1 rue des Trois Cheminées, 37150 Luzillé',
        'radius' => 15000,
    ]);
});

/**
 * Optimisation du chargement : connexion anticipée aux serveurs de polices Google
 * (gagne le DNS + TLS avant le téléchargement des polices).
 * Le préchargement de l'image hero est géré dans inc/seo.php.
 */
add_action('wp_head', static function (): void {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 1);

/**
 * Favicon : utilise le logo du thème (assets/img/logo.png).
 */
add_action('wp_head', static function (): void {
    $url = LUZIAPI_URI . '/assets/img/logo.png';
    $ver = (string) (@filemtime(LUZIAPI_DIR . '/assets/img/logo.png') ?: LUZIAPI_VERSION);
    $src = esc_url($url . '?v=' . $ver);
    echo '<link rel="icon" type="image/png" href="' . $src . '" sizes="any">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . $src . '">' . "\n";
}, 2);

/**
 * Anti-spam du formulaire de contact (Contact Form 7) : honeypot, sans service tiers.
 * Un champ piège invisible — rempli par les bots, jamais par un humain — marque l'envoi comme spam.
 */
add_filter('wpcf7_form_elements', static function (string $html): string {
    $hp = '<span class="lz-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto;width:1px;height:1px;overflow:hidden">'
        . '<label>Laissez ce champ vide&nbsp;: <input type="text" name="luziapi_hp" value="" tabindex="-1" autocomplete="off"></label></span>';

    return $hp . $html;
});
add_filter('wpcf7_spam', static function ($spam, $submission = null) {
    if ($spam) {
        return $spam;
    }

    return ! empty($_POST['luziapi_hp']);
}, 9, 2);

/**
 * Bouton d'appel « SOS essaim » avec icône SVG monochrome (suit la couleur du texte = blanc).
 * Rendu via shortcode pour éviter que WordPress filtre le SVG inline dans le contenu.
 */
add_shortcode('sos_call', static function (): string {
    $svg = '<svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 '
        . '19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11'
        . 'L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';

    return '<a class="btn btn-sos" href="tel:+33632853493">' . $svg . ' Signaler un essaim — 06 32 85 34 93</a>';
});
