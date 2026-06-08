<?php

/**
 * Contexte global Timber : données disponibles dans tous les templates Twig.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

add_filter('timber/context', static function (array $context): array {
    $context['site_name'] = get_bloginfo('name');
    $context['theme_uri'] = LUZIAPI_URI;

    // Coordonnées de l'entreprise (= lieu de retrait), réutilisées partout.
    $context['contact'] = [
        'nom'       => 'Anthony Graule',
        'marque'    => 'LuziApi',
        'adresse'   => '1 rue des Trois Cheminées',
        'cp_ville'  => '37150 Luzillé',
        'tel'       => '06 32 85 34 93',
        'tel_lien'  => '+33632853493',
        'email'     => 'luziapi37150@gmail.com',
        'facebook'  => 'https://www.facebook.com/', // ← remplacer par l'URL exacte de la page Facebook
    ];

    // Crédit photo affiché en pied de page.
    $context['credit_photo'] = 'Thomas Bourdilleau';

    return $context;
});

/**
 * Petites fonctions Twig utiles (formatage du prix WooCommerce, etc.).
 */
add_filter('timber/twig', static function (\Twig\Environment $twig): \Twig\Environment {
    $twig->addFunction(new \Twig\TwigFunction('asset', static function (string $path): string {
        return LUZIAPI_URI . '/assets/' . ltrim($path, '/');
    }));

    return $twig;
});
