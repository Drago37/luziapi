<?php

/**
 * Pages.
 */

declare(strict_types=1);

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

// Gabarit dédié pour la page anglaise (présentation pour les touristes).
if ($post && $post->post_name === 'en') {
    array_unshift($templates, 'page-en.twig');
    $context['honeys_en'] = function_exists('luziapi_get_honeys_en') ? luziapi_get_honeys_en() : [];
}

Timber\Timber::render($templates, $context);
