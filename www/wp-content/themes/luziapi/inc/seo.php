<?php

/**
 * Référencement (SEO) de base, fourni par le thème.
 *
 * Tout est automatiquement désactivé si un plugin SEO majeur est actif
 * (Yoast, Rank Math, SEOPress, AIOSEO), afin d'éviter les balises en double :
 * dans ce cas, on laisse le plugin gérer meta/Open Graph/données structurées.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Un plugin SEO majeur est-il actif ?
 */
function luziapi_seo_plugin_active(): bool
{
    return defined('WPSEO_VERSION')
        || defined('RANK_MATH_VERSION')
        || defined('SEOPRESS_VERSION')
        || defined('AIOSEO_VERSION');
}

/**
 * Préchargement de l'image hero sur l'accueil (améliore le LCP / Core Web Vitals).
 */
add_action('wp_head', static function (): void {
    if (is_front_page()) {
        printf(
            '<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n",
            esc_url(LUZIAPI_URI . '/assets/img/hero.jpg')
        );
    }
}, 1);

/**
 * Meta description + Open Graph / Twitter (repli, si pas de plugin SEO).
 */
add_action('wp_head', static function (): void {
    if (luziapi_seo_plugin_active()) {
        return;
    }

    $site  = get_bloginfo('name');
    $image = LUZIAPI_URI . '/assets/img/hero.jpg';
    $type  = 'website';
    $url   = home_url('/');

    if (is_front_page()) {
        $title = $site . ' · Miel artisanal à Luzillé (Indre-et-Loire)';
        $desc  = 'Miel artisanal récolté, extrait et mis en pot à Luzillé (37) par Anthony Graule : printemps, acacia, châtaignier, tournesol. Vente directe et livraison gratuite à Luzillé et Bléré.';
    } elseif (is_singular()) {
        $post = get_post();
        if ($post instanceof \WP_Post) {
            $title = get_the_title($post) . ' · ' . $site;
            $desc  = has_excerpt($post)
                ? get_the_excerpt($post)
                : wp_trim_words(wp_strip_all_tags($post->post_content), 30);
            $image = has_post_thumbnail($post)
                ? (string) get_the_post_thumbnail_url($post, 'large')
                : $image;
            $url   = (string) get_permalink($post);
            $type  = is_singular('post') ? 'article' : 'website';
        } else {
            $title = wp_get_document_title();
            $desc  = (string) get_bloginfo('description');
        }
    } else {
        $title = wp_get_document_title();
        $desc  = (string) get_bloginfo('description');
    }

    echo "\n<!-- LuziApi SEO -->\n";
    printf('<meta name="description" content="%s">' . "\n", esc_attr($desc));
    printf('<meta property="og:locale" content="%s">' . "\n", 'fr_FR');
    printf('<meta property="og:type" content="%s">' . "\n", esc_attr($type));
    printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr($site));
    printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
    printf('<meta property="og:description" content="%s">' . "\n", esc_attr($desc));
    printf('<meta property="og:url" content="%s">' . "\n", esc_url($url));
    printf('<meta property="og:image" content="%s">' . "\n", esc_url($image));
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}, 5);

/**
 * Données structurées JSON-LD (Schema.org).
 */
add_action('wp_head', static function (): void {
    if (luziapi_seo_plugin_active()) {
        return;
    }

    $blocks = [];

    $blocks[] = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => get_bloginfo('name'),
        'url'      => home_url('/'),
    ];

    if (is_front_page()) {
        $blocks[] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            'name'        => 'LuziApi',
            'description' => 'Apiculteur à Luzillé (Indre-et-Loire) : miel artisanal (printemps, acacia, châtaignier, tournesol) en vente directe, et récupération gratuite d\'essaims d\'abeilles dans un rayon de 15 km.',
            'url'         => home_url('/'),
            'telephone'   => '+33632853493',
            'email'       => 'luziapi37150@gmail.com',
            'image'       => LUZIAPI_URI . '/assets/img/hero.jpg',
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => '1 rue des Trois Cheminées',
                'postalCode'      => '37150',
                'addressLocality' => 'Luzillé',
                'addressRegion'   => 'Centre-Val de Loire',
                'addressCountry'  => 'FR',
            ],
            'geo' => [
                '@type'     => 'GeoCoordinates',
                'latitude'  => 47.2861,
                'longitude' => 1.1206,
            ],
            'areaServed' => [
                '@type'       => 'GeoCircle',
                'geoMidpoint' => ['@type' => 'GeoCoordinates', 'latitude' => 47.2861, 'longitude' => 1.1206],
                'geoRadius'   => 15000,
            ],
            'priceRange' => '€',
            'makesOffer' => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'EUR',
                'itemOffered'   => [
                    '@type'      => 'Service',
                    'name'       => 'Récupération gratuite d\'essaims d\'abeilles',
                    'areaServed' => 'Luzillé et 15 km alentour (Indre-et-Loire)',
                ],
            ],
            'sameAs'     => ['https://www.facebook.com/luziapi'],
        ];
    }

    if (is_singular('post')) {
        $post = get_post();
        if ($post instanceof \WP_Post) {
            $blocks[] = [
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => get_the_title($post),
                'datePublished'    => get_the_date('c', $post),
                'dateModified'     => get_the_modified_date('c', $post),
                'author'           => ['@type' => 'Person', 'name' => 'Anthony Graule'],
                'image'            => has_post_thumbnail($post)
                    ? (string) get_the_post_thumbnail_url($post, 'large')
                    : '',
                'mainEntityOfPage' => (string) get_permalink($post),
            ];
        }
    }

    // Note : sur les fiches produit, WooCommerce génère déjà nativement le JSON-LD
    // Product/Offer (prix, devise, disponibilité). On ne le duplique donc pas ici.
    // La disponibilité « à venir » (PreOrder) de nos miels saisonniers est ajustée
    // via le filtre woocommerce_structured_data_product dans inc/woocommerce.php.

    if (is_page('recuperation-essaims')) {
        $faq = [
            ['Que faire si un essaim d\'abeilles se pose chez moi ?', 'Ne vous approchez pas et n\'y touchez pas. N\'utilisez aucun produit (insecticide, eau, fumée) et tenez enfants et animaux à distance. Appelez un apiculteur : Anthony se déplace gratuitement dans un rayon d\'environ 15 km autour de Luzillé.'],
            ['La récupération d\'un essaim est-elle payante ?', 'Non, c\'est gratuit. Anthony récupère les essaims d\'abeilles dans un rayon d\'environ 15 km autour de Luzillé (Indre-et-Loire).'],
            ['Faut-il détruire un essaim d\'abeilles ?', 'Non. Un essaim de passage est rarement agressif et les abeilles sont essentielles à la biodiversité. Plutôt que de le détruire, faites appel à un apiculteur : chaque essaim sauvé repart en ruche.'],
            ['Intervenez-vous pour les guêpes ou les frelons ?', 'Non, le service concerne uniquement les essaims d\'abeilles. Pour les guêpes ou les frelons, adressez-vous à un professionnel de la désinsectisation.'],
        ];
        $entities = [];
        foreach ($faq as $qa) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $qa[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
            ];
        }
        $blocks[] = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    foreach ($blocks as $block) {
        $json = wp_json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            echo '<script type="application/ld+json">' . $json . "</script>\n";
        }
    }
}, 6);
