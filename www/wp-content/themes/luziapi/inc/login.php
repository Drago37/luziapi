<?php

/**
 * Personnalisation de l'écran de connexion WordPress.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Charge l'identité visuelle LuziApi sans modifier le fonctionnement natif du formulaire.
 */
add_action('login_enqueue_scripts', static function (): void {
    $css_file = get_template_directory() . '/assets/css/login.css';

    wp_enqueue_style(
        'luziapi-login-fonts',
        'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800;9..144,900&family=Hanken+Grotesk:wght@400;500;600;700&display=swap',
        [],
        null
    );

    wp_enqueue_style(
        'luziapi-login',
        get_template_directory_uri() . '/assets/css/login.css',
        ['luziapi-login-fonts'],
        (string) (@filemtime($css_file) ?: LUZIAPI_VERSION)
    );
});

/**
 * Le logo ramène au site plutôt qu'à wordpress.org.
 */
add_filter('login_headerurl', static fn (): string => home_url('/'));

/**
 * Libellé accessible du logo.
 */
add_filter('login_headertext', static fn (): string => 'LuziApi — Retour au site');

/**
 * Courte introduction affichée au-dessus du formulaire.
 */
add_filter('login_message', static function (string $message): string {
    $welcome = '<div class="luziapi-login-welcome">'
        . '<span>Espace d’administration</span>'
        . '<strong>Heureux de vous revoir</strong>'
        . '<p>Connectez-vous pour prendre soin du site, comme du rucher.</p>'
        . '</div>';

    return $welcome . $message;
});
