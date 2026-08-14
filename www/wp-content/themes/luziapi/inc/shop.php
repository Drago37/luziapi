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
 * Un produit est-il en « Récolte annulée » cette année ?
 * (interrupteur manuel : saison sautée, ex. sécheresse — ne revient pas cette année)
 */
function luziapi_is_no_harvest(\WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_luziapi_no_harvest', true) === 'yes';
}

/**
 * Libellé de l'état « Récolte annulée » (personnalisable par produit, sinon défaut).
 */
function luziapi_no_harvest_label(\WC_Product $product): string
{
    $custom = trim((string) get_post_meta($product->get_id(), '_luziapi_no_harvest_label', true));

    return '' !== $custom ? $custom : __('Récolte annulée (sécheresse)', 'luziapi');
}

/**
 * Version courte du libellé (ruban / bouton) : « Récolte annulée (sécheresse) » → « Récolte annulée ».
 */
function luziapi_no_harvest_label_short(\WC_Product $product): string
{
    return trim((string) preg_replace('/\s*\(.*$/u', '', luziapi_no_harvest_label($product)));
}

/**
 * Lien explicatif optionnel (article de blog) associé à l'état « Récolte annulée ».
 */
function luziapi_no_harvest_url(\WC_Product $product): string
{
    return trim((string) get_post_meta($product->get_id(), '_luziapi_no_harvest_url', true));
}

/**
 * Un produit est-il indisponible à la vente ? (récolte annulée, interrupteur « À venir »,
 * ou rupture de stock). Regroupe les cas qui masquent l'achat et l'habillent.
 */
function luziapi_is_coming_soon(\WC_Product $product): bool
{
    if (luziapi_is_no_harvest($product)) {
        return true;
    }
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
        $imageId = absint($product->get_image_id());
        $image  = $imageId > 0
            ? wp_get_attachment_image_url($imageId, 'large')
            : null;

        $noHarvest = luziapi_is_no_harvest($product);

        $honeys[] = [
            'id'          => $product->get_id(),
            'name'        => $product->get_name(),
            'permalink'   => get_permalink($product->get_id()),
            'price_html'  => $product->get_price_html(),
            'desc'        => wp_strip_all_tags($product->get_short_description()),
            'tag'         => (string) get_post_meta($product->get_id(), '_luziapi_tag', true),
            'coming'      => luziapi_is_coming_soon($product),
            'no_harvest'  => $noHarvest,
            // Libellé du ruban et du bouton désactivé : court, selon l'état.
            'avail_label' => $noHarvest ? luziapi_no_harvest_label_short($product) : __('À venir', 'luziapi'),
            'image'       => $image,
            'jar_fill'    => $colors[0],
            'jar_light'   => $colors[1],
            'add_url'     => $product->add_to_cart_url(),
            'badges'      => function_exists('luziapi_product_badges_html') ? luziapi_product_badges_html($product, true) : '',
        ];
    }

    return $honeys;
}

/**
 * Miels présentés sur la page anglaise (/en/) : nom + description en anglais,
 * prix et disponibilité tirés de WooCommerce, couleurs du pot réutilisées.
 *
 * @return array<int,array<string,mixed>>
 */
function luziapi_get_honeys_en(int $limit = 8): array
{
    if (! function_exists('wc_get_products')) {
        return [];
    }

    $map = [
        'miel-de-printemps'   => ['Spring Honey', 'The first harvest of the year — mild and light, with a creamy texture, mostly from rapeseed blossom.'],
        'miel-d-acacia'       => ['Acacia Honey', 'Our mildest honey: clear and runny, it stays liquid for a long time, with delicate floral notes.'],
        'miel-de-chataignier' => ['Chestnut Honey', 'A bold, amber honey with powerful woodland notes — for those who love character.'],
        'miel-de-tournesol'   => ['Sunflower Honey', 'Sunshine-yellow and naturally creamy: a tender, smooth honey with a gentle taste.'],
    ];

    $months = [
        'Janvier' => 'January', 'Février' => 'February', 'Mars' => 'March', 'Avril' => 'April',
        'Mai' => 'May', 'Juin' => 'June', 'Juillet' => 'July', 'Août' => 'August',
        'Septembre' => 'September', 'Octobre' => 'October', 'Novembre' => 'November', 'Décembre' => 'December',
    ];

    $products = wc_get_products([
        'status'  => 'publish',
        'limit'   => $limit,
        'orderby' => 'menu_order',
        'order'   => 'ASC',
    ]);

    $out = [];
    foreach ($products as $product) {
        $slug    = $product->get_slug();
        $colors  = luziapi_jar_colors($slug);
        $en      = $map[$slug] ?? [$product->get_name(), ''];
        $price   = $product->get_price();
        $coming  = luziapi_is_coming_soon($product);
        $recolte = function_exists('luziapi_product_attr') ? trim((string) luziapi_product_attr($product, 'Récolte')) : '';
        $harvest = $months[$recolte] ?? $recolte;

        $noHarvest = luziapi_is_no_harvest($product);
        if ($noHarvest) {
            $availability = 'No harvest this year';
        } elseif ($coming) {
            $availability = ('' !== $harvest) ? 'Available from ' . $harvest : 'Coming soon';
        } else {
            $availability = 'Available now';
        }

        $out[] = [
            'name'         => $en[0],
            'desc'         => $en[1],
            'price'        => ('' !== $price) ? '€' . rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.') : '',
            'coming'       => $coming,
            'no_harvest'   => $noHarvest,
            'availability' => $availability,
            'harvest'      => $harvest,
            'jar_fill'     => $colors[0],
            'jar_light'    => $colors[1],
            'permalink'    => get_permalink($product->get_id()),
        ];
    }

    return $out;
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
    $a_venir     = get_post_meta($post->ID, '_luziapi_a_venir', true);
    $tag         = (string) get_post_meta($post->ID, '_luziapi_tag', true);
    $no_harvest  = get_post_meta($post->ID, '_luziapi_no_harvest', true);
    $nh_label    = (string) get_post_meta($post->ID, '_luziapi_no_harvest_label', true);
    $nh_url      = (string) get_post_meta($post->ID, '_luziapi_no_harvest_url', true);
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
    <hr>
    <p>
        <label>
            <input type="checkbox" name="luziapi_no_harvest" value="yes" <?php checked($no_harvest, 'yes'); ?> />
            <?php esc_html_e('Récolte annulée cette année (saison sautée, ex. sécheresse)', 'luziapi'); ?>
        </label>
    </p>
    <p>
        <label for="luziapi_no_harvest_label"><strong><?php esc_html_e('Libellé affiché', 'luziapi'); ?></strong></label><br>
        <input type="text" id="luziapi_no_harvest_label" name="luziapi_no_harvest_label" value="<?php echo esc_attr($nh_label); ?>"
               class="widefat" placeholder="<?php esc_attr_e('Récolte annulée (sécheresse)', 'luziapi'); ?>" />
    </p>
    <p>
        <label for="luziapi_no_harvest_url"><strong><?php esc_html_e('Lien « Pourquoi ? » (article, optionnel)', 'luziapi'); ?></strong></label><br>
        <input type="url" id="luziapi_no_harvest_url" name="luziapi_no_harvest_url" value="<?php echo esc_attr($nh_url); ?>"
               class="widefat" placeholder="https://www.luziapi.fr/…" />
    </p>
    <p class="description">
        <?php esc_html_e('Différent de « À venir » : indique une saison sans récolte (ne revient pas cette année). Désactive l\'achat et affiche un badge dédié.', 'luziapi'); ?>
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
    update_post_meta(
        $post_id,
        '_luziapi_no_harvest',
        isset($_POST['luziapi_no_harvest']) ? 'yes' : 'no'
    );
    update_post_meta(
        $post_id,
        '_luziapi_no_harvest_label',
        sanitize_text_field(wp_unslash($_POST['luziapi_no_harvest_label'] ?? ''))
    );
    update_post_meta(
        $post_id,
        '_luziapi_no_harvest_url',
        esc_url_raw(wp_unslash($_POST['luziapi_no_harvest_url'] ?? ''))
    );
});
