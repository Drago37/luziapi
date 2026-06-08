<?php

/**
 * Logique boutique LuziApi :
 *  - récupération des miels pour la page d'accueil ;
 *  - état « À venir » (stock = 0 OU interrupteur manuel) ;
 *  - couleur du pot illustré selon le miel ;
 *  - remise de 1 € par pot dès 2 pots ;
 *  - réglages produit dans l'admin (sous-titre + « À venir »).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Couleurs de pot (remplissage / reflet) selon le type de miel, par slug.
 *
 * @return array{0:string,1:string}
 */
function luziapi_jar_colors(string $slug): array
{
    $slug = sanitize_title($slug);
    $map = [
        'miel-de-printemps' => ['#e6dcc6', '#f8f2e4'], // blanc / crémeux (colza)
        'printemps'         => ['#e6dcc6', '#f8f2e4'],
        'miel-d-acacia'     => ['#e7c25a', '#f6e3a0'], // doré pâle
        'acacia'            => ['#e7c25a', '#f6e3a0'],
        'miel-de-chataignier' => ['#8a4d18', '#bd7a2c'], // ambré foncé
        'chataignier'       => ['#8a4d18', '#bd7a2c'],
        'miel-de-tournesol' => ['#eaa90c', '#f7cf52'], // jaune vif
        'tournesol'         => ['#eaa90c', '#f7cf52'],
    ];

    foreach ($map as $key => $colors) {
        if (str_contains($slug, $key)) {
            return $colors;
        }
    }

    return ['#e0a124', '#f2c75a']; // miel par défaut
}

/**
 * Un produit est-il « À venir » ? (rupture de stock OU interrupteur manuel)
 */
function luziapi_is_coming_soon(\WC_Product $product): bool
{
    if (get_post_meta($product->get_id(), '_luziapi_a_venir', true) === 'yes') {
        return true;
    }

    return ! $product->is_in_stock();
}

/**
 * Récupère les miels à afficher sur la page d'accueil.
 *
 * @return array<int,array<string,mixed>>
 */
function luziapi_get_honeys(int $limit = 8): array
{
    if (! function_exists('wc_get_products')) {
        return [];
    }

    $products = wc_get_products([
        'status'  => 'publish',
        'limit'   => $limit,
        'orderby' => 'menu_order',
        'order'   => 'ASC',
    ]);
    /** @var array<int,\WC_Product> $products */

    $honeys = [];
    foreach ($products as $product) {
        $slug   = $product->get_slug();
        $colors = luziapi_jar_colors($slug);
        $image  = $product->get_image_id()
            ? wp_get_attachment_image_url($product->get_image_id(), 'large')
            : null;

        $honeys[] = [
            'name'       => $product->get_name(),
            'permalink'  => get_permalink($product->get_id()),
            'price_html' => $product->get_price_html(),
            'desc'       => wp_strip_all_tags($product->get_short_description()),
            'tag'        => (string) get_post_meta($product->get_id(), '_luziapi_tag', true),
            'coming'     => luziapi_is_coming_soon($product),
            'image'      => $image,
            'jar_fill'   => $colors[0],
            'jar_light'  => $colors[1],
            'add_url'    => $product->add_to_cart_url(),
        ];
    }

    return $honeys;
}

/**
 * Remise : −1 € par pot dès que le panier contient au moins 2 pots.
 */
add_action('woocommerce_cart_calculate_fees', static function (\WC_Cart $cart): void {
    if (is_admin() && ! defined('DOING_AJAX')) {
        return;
    }

    $qty = (int) $cart->get_cart_contents_count();
    if ($qty >= 2) {
        $cart->add_fee(
            sprintf(__('Remise (−1 € par pot dès 2 pots) × %d', 'luziapi'), $qty),
            -1 * $qty
        );
    }
});

/* -------------------------------------------------------------------------
 *  Réglages produit dans l'admin (sans code) : sous-titre + « À venir »
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes', static function (): void {
    add_meta_box(
        'luziapi_product_options',
        __('Options LuziApi', 'luziapi'),
        'luziapi_product_metabox',
        'product',
        'side'
    );
});

function luziapi_product_metabox(\WP_Post $post): void
{
    wp_nonce_field('luziapi_product_options', 'luziapi_product_nonce');
    $a_venir = get_post_meta($post->ID, '_luziapi_a_venir', true);
    $tag     = (string) get_post_meta($post->ID, '_luziapi_tag', true);
    ?>
    <p>
        <label>
            <input type="checkbox" name="luziapi_a_venir" value="yes" <?php checked($a_venir, 'yes'); ?> />
            <?php esc_html_e('Annoncer comme « À venir » (force l\'indisponibilité)', 'luziapi'); ?>
        </label>
    </p>
    <p>
        <label for="luziapi_tag"><strong><?php esc_html_e('Sous-titre (étiquette)', 'luziapi'); ?></strong></label><br>
        <input type="text" id="luziapi_tag" name="luziapi_tag" value="<?php echo esc_attr($tag); ?>"
               class="widefat" placeholder="<?php esc_attr_e('Ex. Récolte de printemps', 'luziapi'); ?>" />
    </p>
    <p class="description">
        <?php esc_html_e('Le statut « À venir » s\'active aussi automatiquement quand le stock atteint 0.', 'luziapi'); ?>
    </p>
    <?php
}

add_action('save_post_product', static function (int $post_id): void {
    if (! isset($_POST['luziapi_product_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['luziapi_product_nonce'])), 'luziapi_product_options')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    update_post_meta(
        $post_id,
        '_luziapi_a_venir',
        isset($_POST['luziapi_a_venir']) ? 'yes' : 'no'
    );
    update_post_meta(
        $post_id,
        '_luziapi_tag',
        sanitize_text_field(wp_unslash($_POST['luziapi_tag'] ?? ''))
    );
});
