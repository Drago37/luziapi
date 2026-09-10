<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use LuziApi\OrderTracking\Application\Port\MagicLinkUrlGenerator;

final readonly class WordPressTrackingUrlGenerator implements MagicLinkUrlGenerator
{
    public const PAGE_SLUG = 'suivi-commande';

    public function forToken(string $token): string
    {
        return add_query_arg('acces', rawurlencode($token), $this->pageUrl());
    }

    public function forOrderNumber(string $orderNumber): string
    {
        return add_query_arg('commande', rawurlencode($orderNumber), $this->pageUrl());
    }

    public function pageUrl(): string
    {
        $page = get_page_by_path(self::PAGE_SLUG);
        if ($page instanceof \WP_Post && 'publish' === $page->post_status) {
            return (string) get_permalink($page);
        }

        return home_url('/' . self::PAGE_SLUG . '/');
    }

    public function publishedPageUrl(): string
    {
        $page = get_page_by_path(self::PAGE_SLUG);

        return $page instanceof \WP_Post && 'publish' === $page->post_status
            ? (string) get_permalink($page)
            : '';
    }
}
