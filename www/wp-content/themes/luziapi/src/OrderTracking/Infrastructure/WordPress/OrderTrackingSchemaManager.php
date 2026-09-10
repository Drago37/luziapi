<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use wpdb;

final readonly class OrderTrackingSchemaManager
{
    public const VERSION = '1';
    private const OPTION = 'luziapi_order_tracking_schema_version';

    public function __construct(private wpdb $database)
    {
    }

    public function migrate(): void
    {
        if (self::VERSION === get_option(self::OPTION)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->database->get_charset_collate();
        $grants = $this->grantsTableName();
        dbDelta("CREATE TABLE {$grants} (
            token_hash char(64) NOT NULL,
            order_ids longtext NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) {$charset};");

        $sessions = $this->sessionsTableName();
        dbDelta("CREATE TABLE {$sessions} (
            token_hash char(64) NOT NULL,
            order_ids longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) {$charset};");

        $limits = $this->limitsTableName();
        dbDelta("CREATE TABLE {$limits} (
            scope varchar(40) NOT NULL,
            subject_hash char(64) NOT NULL,
            window_started_at datetime NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (scope, subject_hash),
            KEY window_started_at (window_started_at)
        ) {$charset};");

        $events = $this->eventsTableName();
        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            from_status varchar(40) NOT NULL,
            to_status varchar(40) NOT NULL,
            occurred_at datetime NOT NULL,
            reference_key char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reference_key (reference_key),
            KEY order_id (order_id),
            KEY occurred_at (occurred_at)
        ) {$charset};");

        update_option(self::OPTION, self::VERSION, false);
    }

    public function grantsTableName(): string
    {
        return $this->database->prefix . 'luziapi_tracking_grants';
    }

    public function sessionsTableName(): string
    {
        return $this->database->prefix . 'luziapi_tracking_sessions';
    }

    public function limitsTableName(): string
    {
        return $this->database->prefix . 'luziapi_tracking_rate_limits';
    }

    public function eventsTableName(): string
    {
        return $this->database->prefix . 'luziapi_tracking_status_events';
    }
}
