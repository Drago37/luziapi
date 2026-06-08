<?php

/**
 * Article (blog) — vue détaillée.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context         = Timber\Timber::context();
$context['post'] = Timber\Timber::get_post();

Timber\Timber::render(['single.twig'], $context);
