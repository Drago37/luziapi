<?php

/**
 * Page des articles (blog) — utilisée quand une « page des articles »
 * est définie dans Réglages → Lecture.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context          = Timber\Timber::context();
$context['posts'] = Timber\Timber::get_posts();

Timber\Timber::render(['blog.twig'], $context);
