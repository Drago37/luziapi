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

Timber\Timber::render($templates, $context);
