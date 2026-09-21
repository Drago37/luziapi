<?php

/**
 * Pages.
 */

declare(strict_types=1);

use LuziApi\Shared\Infrastructure\Wp;

if (! defined('ABSPATH')) {
    exit;
}

$context         = Timber\Timber::context();
$post            = Timber\Timber::get_post();
$context['post'] = $post;

$templates = ['page.twig'];

// Gabarit dédié pour la page « Mentions légales ».
if ($post && $post->post_name === 'mentions-legales') {
    array_unshift($templates, 'page-mentions-legales.twig');
}

// Pages contractuelles de la boutique, versionnées dans le thème.
if ($post && $post->post_name === 'conditions-generales-de-vente') {
    array_unshift($templates, 'page-conditions-generales-de-vente.twig');
    $context['cgv_version']        = defined('LUZIAPI_CGV_VERSION') ? LUZIAPI_CGV_VERSION : '';
    $context['cgv_label']          = defined('LUZIAPI_CGV_LABEL') ? LUZIAPI_CGV_LABEL : '';
    $context['cgv_pdf_url']        = function_exists('luziapi_cgv_pdf_url') ? luziapi_cgv_pdf_url() : '';
    $context['withdrawal_url']     = function_exists('luziapi_withdrawal_url') ? luziapi_withdrawal_url() : '';
    $context['loyalty_program_url'] = function_exists('luziapi_loyalty_page_url') ? luziapi_loyalty_page_url() : '';
    $context['loyalty']            = function_exists('luziapi_loyalty_program_numbers') ? luziapi_loyalty_program_numbers() : [];
}

if ($post && $post->post_name === 'retractation') {
    array_unshift($templates, 'page-retractation.twig');
    $context['withdrawal'] = function_exists('luziapi_withdrawal_page_context')
        ? luziapi_withdrawal_page_context()
        : [];
}

if ($post && $post->post_name === 'politique-de-confidentialite') {
    array_unshift($templates, 'page-politique-de-confidentialite.twig');
}

// Page publique du programme de fidélité (règles complètes + règlement versionné).
if ($post && $post->post_name === 'programme-de-fidelite') {
    array_unshift($templates, 'page-programme-de-fidelite.twig');
    $context['loyalty']                 = function_exists('luziapi_loyalty_program_numbers') ? luziapi_loyalty_program_numbers() : [];
    $context['loyalty_reglement_pdf']   = function_exists('luziapi_loyalty_reglement_pdf_url') ? luziapi_loyalty_reglement_pdf_url() : '';
    $context['cgv_url']                 = function_exists('luziapi_cgv_url') ? luziapi_cgv_url() : '';
}

if ($post && $post->post_name === 'suivi-commande') {
    array_unshift($templates, 'page-suivi-commande.twig');
}

// Gabarit dédié pour la page anglaise (présentation pour les touristes).
if ($post && $post->post_name === 'en') {
    array_unshift($templates, 'page-en.twig');
    $context['honeys_en'] = function_exists('luziapi_get_honeys_en') ? luziapi_get_honeys_en() : [];
    $en_cf7 = Wp::int(get_option('luziapi_cf7_en_id'));
    $context['contact_form_shortcode'] = $en_cf7
        ? '[contact-form-7 id="' . $en_cf7 . '" title="Contact (English)"]'
        : (defined('LUZIAPI_CF7') ? LUZIAPI_CF7 : '');
}

// Gabarit dédié pour la page « Actualités » : liste complète + tri + filtre par catégorie.
if ($post && $post->post_name === 'actualites') {
    array_unshift($templates, 'page-actualites.twig');
    $context['posts']     = Timber\Timber::get_posts([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    $context['news_cats'] = Timber\Timber::get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => true,
        'exclude'    => [Wp::int(get_option('default_category'))],
    ]);
}

Timber\Timber::render($templates, $context);
