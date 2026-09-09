<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use wpdb;

final readonly class PilotageSchemaManager
{
    public const VERSION = '7';
    private const OPTION = 'luziapi_pilotage_receipts_schema_version';

    public function __construct(private wpdb $database)
    {
    }

    public function migrate(): void
    {
        if (self::VERSION === get_option(self::OPTION)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->tableName();
        $charset = $this->database->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sequence_number bigint(20) unsigned NULL,
            order_id bigint(20) unsigned NULL,
            occurred_at datetime NOT NULL,
            amount_cents bigint(20) NOT NULL,
            currency char(3) NOT NULL DEFAULT 'EUR',
            payment_method varchar(40) NOT NULL,
            entry_type varchar(20) NOT NULL,
            description text NOT NULL,
            reversal_of_id bigint(20) unsigned NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sequence_number (sequence_number),
            KEY order_id (order_id),
            KEY occurred_at (occurred_at),
            KEY reversal_of_id (reversal_of_id)
        ) {$charset};");

        $lotsTable = $this->lotsTableName();
        dbDelta("CREATE TABLE {$lotsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(64) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            harvest_year smallint(4) unsigned NOT NULL,
            harvested_at date NOT NULL,
            jarred_at datetime NOT NULL,
            apiary_origin varchar(190) NOT NULL,
            variety varchar(190) NOT NULL,
            quantity_jarred int(11) unsigned NOT NULL,
            stock_already_recorded tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY product_id (product_id),
            KEY harvest_year (harvest_year)
        ) {$charset};");

        $movementsTable = $this->stockMovementsTableName();
        dbDelta("CREATE TABLE {$movementsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sequence_number bigint(20) unsigned NULL,
            product_id bigint(20) unsigned NOT NULL,
            lot_id bigint(20) unsigned NULL,
            order_id bigint(20) unsigned NULL,
            order_item_id bigint(20) unsigned NULL,
            occurred_at datetime NOT NULL,
            quantity_delta int(11) NOT NULL,
            movement_type varchar(30) NOT NULL,
            reason text NOT NULL,
            reference_key varchar(191) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sequence_number (sequence_number),
            UNIQUE KEY reference_key (reference_key),
            KEY product_id (product_id),
            KEY lot_id (lot_id),
            KEY order_id (order_id),
            KEY occurred_at (occurred_at)
        ) {$charset};");

        $activityTable = $this->activityTableName();
        dbDelta("CREATE TABLE {$activityTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            occurred_at datetime NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            category varchar(30) NOT NULL,
            action varchar(64) NOT NULL,
            object_type varchar(40) NOT NULL,
            object_id bigint(20) unsigned NULL,
            summary varchar(255) NOT NULL,
            details_json longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY occurred_at (occurred_at),
            KEY category (category),
            KEY actor_id (actor_id),
            KEY object_lookup (object_type, object_id)
        ) {$charset};");

        $customerCategoriesTable = $this->customerCategoriesTableName();
        dbDelta("CREATE TABLE {$customerCategoriesTable} (
            customer_key char(20) NOT NULL,
            category varchar(30) NOT NULL DEFAULT 'unspecified',
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (customer_key),
            KEY category (category)
        ) {$charset};");

        update_option(self::OPTION, self::VERSION, false);
    }

    public function tableName(): string
    {
        return $this->database->prefix . 'luziapi_receipts';
    }

    public function lotsTableName(): string
    {
        return $this->database->prefix . 'luziapi_harvest_lots';
    }

    public function stockMovementsTableName(): string
    {
        return $this->database->prefix . 'luziapi_stock_movements';
    }

    public function activityTableName(): string
    {
        return $this->database->prefix . 'luziapi_activity_log';
    }

    public function customerCategoriesTableName(): string
    {
        return $this->database->prefix . 'luziapi_customer_categories';
    }
}
