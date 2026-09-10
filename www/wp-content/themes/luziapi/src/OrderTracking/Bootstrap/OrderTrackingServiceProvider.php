<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Bootstrap;

use LuziApi\OrderTracking\Application\Command\RecordOrderStatusChange\RecordOrderStatusChangeHandler;
use LuziApi\OrderTracking\Application\Command\RedeemHistoryLink\RedeemHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RequestHistoryLink\RequestHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RevokeTrackingSession\RevokeTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Command\StartOrderAccess\StartOrderAccessHandler;
use LuziApi\OrderTracking\Application\Query\ResolveTrackingSession\ResolveTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Service\TrackingSessionIssuer;
use LuziApi\OrderTracking\Infrastructure\WooCommerce\WooCommerceOrderTrackingGateway;
use LuziApi\OrderTracking\Infrastructure\WooCommerce\WooCommerceStatusHistorySubscriber;
use LuziApi\OrderTracking\Infrastructure\WordPress\OrderTrackingSchemaManager;
use LuziApi\OrderTracking\Infrastructure\WordPress\RandomTokenGenerator;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressAccessFingerprint;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressClock;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressMagicLinkSender;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressStatusHistoryRepository;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingAccessRepository;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingSessionCookie;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingUrlGenerator;
use LuziApi\OrderTracking\UserInterface\Web\TrackingPageController;
use wpdb;

final class OrderTrackingServiceProvider
{
    private static bool $booted = false;

    public static function boot(string $themeUri): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $logger = luziapi_logger();
        $clock = new WordPressClock();
        $schema = new OrderTrackingSchemaManager($wpdb);
        $access = new WordPressTrackingAccessRepository($wpdb, $schema, $logger);
        $fingerprints = new WordPressAccessFingerprint(wp_salt('auth') . wp_salt('secure_auth'));
        $tokens = new RandomTokenGenerator();
        $urls = new WordPressTrackingUrlGenerator();
        $statusHistory = new WordPressStatusHistoryRepository($wpdb, $schema, wp_timezone());
        $orders = new WooCommerceOrderTrackingGateway($statusHistory);
        $sessions = new TrackingSessionIssuer($access, $tokens, $fingerprints);
        $controller = new TrackingPageController(
            new StartOrderAccessHandler($orders, $access, $sessions, $fingerprints, $clock),
            new RequestHistoryLinkHandler(
                $orders,
                $access,
                $tokens,
                $fingerprints,
                $urls,
                new WordPressMagicLinkSender($themeUri . '/assets/img/logo-email.png', home_url('/'), $logger),
                $clock,
            ),
            new RedeemHistoryLinkHandler($access, $sessions, $fingerprints, $clock),
            new RevokeTrackingSessionHandler($access, $fingerprints),
            new ResolveTrackingSessionHandler($access, $orders, $fingerprints, $clock),
            $access,
            new WordPressTrackingSessionCookie($logger),
            $urls,
            $clock,
        );

        add_action('init', [$schema, 'migrate'], 1);
        (new WooCommerceStatusHistorySubscriber(new RecordOrderStatusChangeHandler($statusHistory, $clock), $logger))->register();
        $controller->register();
    }
}
