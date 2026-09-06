<?php

declare(strict_types=1);

/*
 * Bootstrap des tests des fonctions pures LuziApi.
 *
 * Au *chargement* du fichier, luziapi-newsletter-autosend.php n'appelle qu'une
 * seule fonction WordPress : add_action(). On la remplace par un stub no-op pour
 * pouvoir inclure le plugin hors WordPress et tester ses fonctions SMS pures
 * (normalisation GSM, comptage de segments, composition du message livré, etc.).
 *
 * Les fonctions qui dépendent réellement de WordPress (get_post_meta,
 * wp_get_shortlink, WP_Post…) ne sont pas testées ici : elles se contentent
 * d'assembler des valeurs puis d'appeler les fonctions pures ci-dessus.
 */
if (!function_exists('add_action')) {
    function add_action(...$args): void
    {
    }
}

if (!function_exists('add_filter')) {
    function add_filter(...$args): void
    {
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents(string $value): string
    {
        return strtr($value, [
            'é' => 'e',
            'É' => 'E',
            'è' => 'e',
            'È' => 'E',
            'ê' => 'e',
            'Ê' => 'E',
            'à' => 'a',
            'À' => 'A',
            'ù' => 'u',
            'Ù' => 'U',
            'î' => 'i',
            'Î' => 'I',
            'ô' => 'o',
            'Ô' => 'O',
        ]);
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require __DIR__ . '/../prod-mu-plugins/luziapi-newsletter-autosend.php';
require __DIR__ . '/../www/wp-content/themes/luziapi/inc/order-workflow.php';
