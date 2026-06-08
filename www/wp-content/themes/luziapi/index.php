<?php

/**
 * Gabarit de repli (liste d'articles).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context          = Timber\Timber::context();
$context['posts'] = Timber\Timber::get_posts();

Timber\Timber::render(['index.twig'], $context);
