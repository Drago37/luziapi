<?php

/**
 * Page 404 (introuvable).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context = Timber\Timber::context();

Timber\Timber::render(['404.twig'], $context);
