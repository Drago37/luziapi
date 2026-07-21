<?php

declare(strict_types=1);

/*
 * Bootstrap des tests des mu-plugins LuziApi.
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

require __DIR__ . '/../prod-mu-plugins/luziapi-newsletter-autosend.php';
