<?php

/**
 * Fixtures de démonstration LuziApi (contenu d'exemple de la page d'accueil).
 *
 * Exécution :  make fixtures
 *   (équivaut à : wp eval-file wp-content/themes/luziapi/tools/fixtures.php --user=admin)
 *
 * Idempotent : relançable sans créer de doublon. Les produits/articles repérés
 * par leur titre sont mis à jour ; les manquants sont créés.
 *
 * Recrée : devise EUR (format français), les 4 miels, les 3 actualités.
 *
 * Note : pas de declare(strict_types) — `wp eval-file` exécute via eval(),
 * qui interdit cette déclaration.
 */

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

/** Retourne l'ID d'un post (n'importe quel statut) par titre exact, ou 0. */
$find_by_title = static function (string $title, string $type): int {
    $ids = get_posts([
        'post_type'   => $type,
        'title'       => $title,
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);

    return $ids ? (int) $ids[0] : 0;
};

/* ------------------------------------------------------------------ */
/*  Réglages WooCommerce : euros, format français « 10 € »            */
/* ------------------------------------------------------------------ */
WP_CLI::log('→ Réglages WooCommerce (EUR, format français)…');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_price_num_decimals', 0);
update_option('woocommerce_price_thousand_sep', ' ');

/* ------------------------------------------------------------------ */
/*  Les 4 miels                                                       */
/* ------------------------------------------------------------------ */
if (! class_exists('WC_Product_Simple')) {
    WP_CLI::error('WooCommerce n\'est pas actif — impossible de créer les produits.');
}

$honeys = [
    ['name' => 'Miel de Printemps',   'price' => '10', 'tag' => 'Récolte de printemps', 'coming' => false, 'desc' => 'À dominante colza. Blanc et crémeux, brassé pour une texture onctueuse, facile à tartiner.'],
    ['name' => "Miel d'Acacia",       'price' => '14', 'tag' => 'Floraison de mai',     'coming' => false, 'desc' => 'Clair et délicat, tout en douceur. Reste liquide longtemps : non brassé.'],
    ['name' => 'Miel de Châtaignier', 'price' => '12', 'tag' => 'Été · sous-bois',      'coming' => true,  'desc' => 'Ambré foncé et corsé, des notes boisées et puissantes. Non brassé.'],
    ['name' => 'Miel de Tournesol',   'price' => '11', 'tag' => 'Plein été',            'coming' => true,  'desc' => 'Jaune lumineux, additionné de 5 à 10 % de miel de printemps et brassé pour rester souple.'],
];

WP_CLI::log('→ Miels…');
$order = 1;
foreach ($honeys as $h) {
    $id      = $find_by_title($h['name'], 'product');
    $existed = $id > 0;

    $product = $id ? wc_get_product($id) : new WC_Product_Simple();
    $product->set_name($h['name']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($h['price']);
    $product->set_short_description($h['desc']);
    $product->set_menu_order($order);
    $id = (int) $product->save();

    update_post_meta($id, '_luziapi_tag', $h['tag']);
    update_post_meta($id, '_luziapi_a_venir', $h['coming'] ? 'yes' : 'no');

    WP_CLI::log(sprintf('  %s %s (#%d)', $existed ? '↻' : '+', $h['name'], $id));
    $order++;
}

/* ------------------------------------------------------------------ */
/*  Les 3 actualités                                                  */
/* ------------------------------------------------------------------ */
$posts = [
    ['title' => 'La miellée de printemps est lancée',  'date' => '2026-05-12 09:00:00', 'excerpt' => 'Les colonies sont fortes et le colza bat son plein : les premières hausses se remplissent.',  'content' => 'Les colonies sont fortes et le colza bat son plein : les premières hausses se remplissent. La saison s\'annonce belle au rucher.'],
    ['title' => 'Désoperculation : la récolte au chaud', 'date' => '2026-06-02 09:00:00', 'excerpt' => 'Retour en images sur l\'extraction du miel de printemps, du cadre jusqu\'au pot.',            'content' => 'Retour en images sur l\'extraction du miel de printemps, du cadre jusqu\'au pot. Désoperculation à la main puis passage à l\'extracteur.'],
    ['title' => 'Le miel de printemps est disponible',  'date' => '2026-06-05 09:00:00', 'excerpt' => 'Mis en pot et prêt à déguster : le miel de printemps rejoint la boutique.',                  'content' => 'Mis en pot et prêt à déguster : le miel de printemps rejoint la boutique. Quantités limitées selon les floraisons.'],
];

WP_CLI::log('→ Actualités…');
foreach ($posts as $p) {
    $id = $find_by_title($p['title'], 'post');
    $data = [
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => $p['title'],
        'post_excerpt' => $p['excerpt'],
        'post_content' => $p['content'],
        'post_date'    => $p['date'],
    ];
    if ($id) {
        $data['ID'] = $id;
        wp_update_post($data);
        WP_CLI::log("  ↻ {$p['title']} (#{$id})");
    } else {
        $id = (int) wp_insert_post($data);
        WP_CLI::log("  + {$p['title']} (#{$id})");
    }
}

/* Retire l'article « Hello world! » par défaut (sinon il masque une actualité). */
$hello = get_page_by_path('hello-world', OBJECT, 'post');
if ($hello) {
    wp_trash_post($hello->ID);
    WP_CLI::log('  − « Hello world! » mis à la corbeille');
}

WP_CLI::success('Fixtures LuziApi en place : 4 miels + 3 actualités.');
