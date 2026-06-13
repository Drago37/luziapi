<?php

/**
 * Blog / actualités : badge de catégorie avec icône.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Icône associée à une catégorie d'article.
 */
function luziapi_category_icon(string $slug): string
{
    $icons = [
        'recolte'     => '🍯',
        'information' => '💡',
        'essaim'      => '🐝',
    ];

    return $icons[$slug] ?? '📝';
}

/**
 * Badge HTML de la (première) catégorie d'un article : icône + nom.
 */
function luziapi_post_category_badge($post_id): string
{
    $cats = get_the_category((int) $post_id);
    if (empty($cats)) {
        return '';
    }

    $cat = $cats[0];

    return '<span class="cat-badge cat-badge--' . esc_attr($cat->slug) . '">'
        . '<span class="cat-badge-ic" aria-hidden="true">' . luziapi_category_icon($cat->slug) . '</span>'
        . esc_html($cat->name) . '</span>';
}
