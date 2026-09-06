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

// Le gestionnaire de clic WooCommerce peut interpréter un clic sur une case de
// sélection comme un clic sur la ligne et ouvrir la commande
// (woocommerce/woocommerce#67906). Couvrir les écrans historique et HPOS, puis
// bloquer uniquement la remontée du clic depuis les cases : le reste de la
// ligne demeure cliquable et les actions groupées fonctionnent à nouveau.
add_action('admin_enqueue_scripts', static function (string $hookSuffix): void {
    $postType = isset($_GET['post_type'])
        ? sanitize_key(wp_unslash((string) $_GET['post_type']))
        : '';
    $action = isset($_GET['action'])
        ? sanitize_key(wp_unslash((string) $_GET['action']))
        : '';

    $isLegacyOrderList = 'edit.php' === $hookSuffix && 'shop_order' === $postType;
    $isHposOrderList   = 'woocommerce_page_wc-orders' === $hookSuffix && '' === $action;

    if (! $isLegacyOrderList && ! $isHposOrderList) {
        return;
    }

    wp_add_inline_script(
        'jquery-core',
        <<<'JS'
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('#the-list input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.addEventListener('click', function (event) {
                        event.stopPropagation();
                    });
                });
            });
            JS
    );
});

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

// Ruban d'indisponibilité sur la vignette produit dans la boutique.
add_action('woocommerce_before_shop_loop_item_title', static function (): void {
    global $product;
    if (! $product instanceof \WC_Product) {
        return;
    }
    if (luziapi_is_no_harvest($product)) {
        echo '<span class="wc-ribbon wc-ribbon--off">' . esc_html(luziapi_no_harvest_label_short($product)) . '</span>';
    } elseif (luziapi_is_coming_soon($product)) {
        echo '<span class="wc-ribbon">' . esc_html__('À venir', 'luziapi') . '</span>';
    }
}, 5);

// Récolte annulée : produit non achetable même s'il reste du stock déclaré.
add_filter('woocommerce_is_purchasable', static function (bool $purchasable, \WC_Product $product): bool {
    return luziapi_is_no_harvest($product) ? false : $purchasable;
}, 10, 2);

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
    $label = 0 === $count ? 'Vide' : sprintf('%d article%s', $count, $count > 1 ? 's' : '');

    $fragments['span.header-cart-state'] = '<span class="header-cart-state">' . esc_html($label) . '</span>';

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

// Le calculateur d'expédition du panier est inutile : les modes réels sont
// déterminés à l'étape de commande selon la ville (livraison gratuite à Bléré
// ou Luzillé, retrait au domicile LuziApi partout ailleurs).
add_filter('pre_option_woocommerce_enable_shipping_calc', static function ($value) {
    if (! is_admin() && function_exists('is_cart') && is_cart()) {
        return 'no';
    }

    return $value;
});

// E-mails : afficher le contact luziapi37150@gmail.com (et non l'expéditeur no-reply)
// dans le corps du message ET en Reply-To. L'expéditeur reste no-reply (délivrabilité).
add_filter('woocommerce_mail_content', static function (string $content): string {
    return str_replace('no-reply@luziapi.fr', 'luziapi37150@gmail.com', $content);
});
add_filter('woocommerce_email_headers', static function ($headers) {
    return str_replace('no-reply@luziapi.fr', 'luziapi37150@gmail.com', (string) $headers);
}, 10, 1);

// Retire le bloc « méta » de la fiche (catégorie / SKU / étiquettes) : inutile ici.
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);

// Encart « offre » réutilisable (fiche produit, boutique, panier).
function luziapi_offer_html(): string
{
    return '<div class="product-offer">'
        . '<span class="product-offer__badge">Offre</span>'
        . '<span><b>À partir de 2 pots&nbsp;: −1&nbsp;€ sur chaque pot.</b> Livraison à domicile gratuite sur Luzillé et Bléré.</span>'
        . '</div>';
}
// Fiche produit : bandeau d'offre en pleine largeur, au-dessus du produit.
add_action('woocommerce_before_single_product', static function (): void {
    echo luziapi_offer_html();
}, 15);
// En haut de la boutique (au-dessus de la grille).
add_action('woocommerce_before_shop_loop', static function (): void {
    echo luziapi_offer_html();
}, 5);
// En haut du panier.
add_action('woocommerce_before_cart', static function (): void {
    echo luziapi_offer_html();
}, 5);

// Supprime la partie « Avis » : onglet de la fiche + note en étoiles sous le titre.
add_filter('woocommerce_product_tabs', static function (array $tabs): array {
    unset($tabs['reviews']);

    return $tabs;
}, 98);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);

// Pot dessiné dans le panier quand le produit n'a pas de photo.
add_filter('woocommerce_cart_item_thumbnail', static function ($thumbnail, $cart_item) {
    $product = $cart_item['data'] ?? null;
    if ($product instanceof \WC_Product && ! $product->get_image_id()) {
        return '<span class="wc-jar wc-jar--cart">' . luziapi_product_jar($product) . '</span>';
    }

    return $thumbnail;
}, 10, 2);

// Récupère la valeur d'un attribut produit (personnalisé) par son libellé.
function luziapi_product_attr(\WC_Product $product, string $label): string
{
    foreach ($product->get_attributes() as $attr) {
        if ($attr instanceof \WC_Product_Attribute && ! $attr->is_taxonomy()
            && mb_strtolower($attr->get_name()) === mb_strtolower($label)) {
            return implode(', ', $attr->get_options());
        }
    }

    return '';
}

// Badges (disponibilité + goût + texture) — réutilisés sur fiche, boutique et accueil.
function luziapi_product_badges_html(\WC_Product $product, bool $compact = false): string
{
    $gout    = luziapi_product_attr($product, 'Goût');
    $texture = luziapi_product_attr($product, 'Texture');
    if ($compact && '' !== $gout) {
        $gout = trim((string) preg_replace('/\s*\(.*$/u', '', $gout)); // raccourci pour les vignettes
    }

    $noHarvest = luziapi_is_no_harvest($product);
    if ($noHarvest) {
        $stock = ['off', luziapi_no_harvest_label($product)];
    } elseif (luziapi_is_coming_soon($product)) {
        $recolte = luziapi_product_attr($product, 'Récolte');
        $stock   = ['soon', '' !== $recolte ? 'À venir · récolte ' . $recolte : 'À venir'];
    } elseif ($product->is_in_stock()) {
        $stock = ['in', 'Disponible'];
    } else {
        $stock = ['out', 'Rupture de stock'];
    }

    $html  = '<div class="product-badges">';
    $html .= '<span class="product-badge product-badge--' . $stock[0] . '"><span class="dot"></span>' . esc_html($stock[1]) . '</span>';
    // Lien explicatif « Pourquoi ? » (fiche produit uniquement) vers l'article dédié.
    if ($noHarvest && ! $compact) {
        $why = luziapi_no_harvest_url($product);
        if ('' !== $why) {
            $html .= '<a class="badge-why" href="' . esc_url($why) . '">' . esc_html__('Pourquoi ?', 'luziapi') . '</a>';
        }
    }
    if ('' !== $gout) {
        $html .= '<span class="product-badge"><b>Goût</b> ' . esc_html($gout) . '</span>';
    }
    if ('' !== $texture) {
        $html .= '<span class="product-badge"><b>Texture</b> ' . esc_html($texture) . '</span>';
    }

    return $html . '</div>';
}

// Fiche produit : badges complets sous le titre.
add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if ($product instanceof \WC_Product) {
        echo luziapi_product_badges_html($product);
    }
}, 6);

// Boutique : badges (compacts) sous le titre de chaque vignette.
add_action('woocommerce_after_shop_loop_item_title', static function (): void {
    global $product;
    if ($product instanceof \WC_Product) {
        echo luziapi_product_badges_html($product, true);
    }
}, 6);

// Boutique : description courte sous les badges de chaque vignette.
add_action('woocommerce_after_shop_loop_item_title', static function (): void {
    global $product;
    if (! $product instanceof \WC_Product) {
        return;
    }
    $short = wp_strip_all_tags($product->get_short_description());
    if ('' !== $short) {
        echo '<p class="loop-desc">' . esc_html($short) . '</p>';
    }
}, 7);

/**
 * Données structurées : marque les miels « à venir » (récolte à venir / hors saison)
 * comme PreOrder dans le JSON-LD Product généré nativement par WooCommerce,
 * plutôt que InStock/OutOfStock — c'est plus juste pour Google (le produit revient).
 */
add_filter('woocommerce_structured_data_product', static function ($markup, $product) {
    if (! is_array($markup) || empty($markup['offers']) || ! $product instanceof \WC_Product) {
        return $markup;
    }

    // Récolte annulée (saison sautée) : OutOfStock, plus honnête que PreOrder.
    // « À venir » (récolte prochaine) : PreOrder, le produit revient bientôt.
    if (function_exists('luziapi_is_no_harvest') && luziapi_is_no_harvest($product)) {
        $availability = 'https://schema.org/OutOfStock';
    } elseif (function_exists('luziapi_is_coming_soon') && luziapi_is_coming_soon($product)) {
        $availability = 'https://schema.org/PreOrder';
    } else {
        return $markup;
    }

    foreach ($markup['offers'] as $i => $offer) {
        $markup['offers'][$i]['availability'] = $availability;
    }

    return $markup;
}, 20, 2);

/**
 * Section « Avis » (Google) — réutilisée sur l'accueil et sous les produits de la boutique.
 * Boutons : laisser un avis + voir les avis sur Google. L'affichage des avis (widget)
 * pourra être branché ici plus tard.
 */
function luziapi_reviews_section_html(): string
{
    $review = 'https://g.page/r/CaBl568csp6SECE/review';
    $page   = 'https://g.page/r/CaBl568csp6SECE';

    ob_start();
    ?>
    <section class="reviews" id="avis">
        <div class="wrap">
            <div class="reviews-card">
                <span class="eyebrow">Vos avis</span>
                <h2>Ils ont goûté nos miels 🍯</h2>
                <p>Votre avis compte&nbsp;! Partagez votre expérience sur Google — et découvrez celle des autres gourmands.</p>
                <div class="reviews-cta">
                    <a class="btn btn-gold" href="<?php echo esc_url($review); ?>" target="_blank" rel="noopener"><span class="rev-star" aria-hidden="true">★</span> Laisser un avis</a>
                    <a class="btn btn-outline-wood" href="<?php echo esc_url($page); ?>" target="_blank" rel="noopener">Voir nos avis sur Google</a>
                </div>
            </div>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}

// Affiche la section avis sous la grille de produits, sur la page Boutique.
add_action('woocommerce_after_shop_loop', static function (): void {
    if (function_exists('is_shop') && is_shop()) {
        echo luziapi_reviews_section_html();
    }
}, 50);
