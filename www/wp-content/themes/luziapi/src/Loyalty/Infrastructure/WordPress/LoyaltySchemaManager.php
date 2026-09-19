<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use wpdb;

/**
 * Journal de fidélité append-only. Chaque ligne est un mouvement immuable de pots
 * (crédit d'achat, contre-passation, et plus tard avantage acquis/consommé). La
 * clé d'idempotence garantit l'unicité d'un mouvement (ex. `credit:{orderId}`).
 */
final readonly class LoyaltySchemaManager
{
    public const VERSION = '2';
    private const OPTION = 'luziapi_loyalty_schema_version';

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
        $ledger = $this->ledgerTableName();

        // varchar(191) pour l'index UNIQUE : borne utf8mb4 (191 * 4 octets < 767).
        dbDelta("CREATE TABLE {$ledger} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_key char(20) NOT NULL,
            entry_type varchar(20) NOT NULL,
            pots_delta int(11) NOT NULL DEFAULT 0,
            rights_delta int(11) NOT NULL DEFAULT 0,
            source_order_id bigint(20) unsigned NULL,
            usage_order_id bigint(20) unsigned NULL,
            reversal_of_id bigint(20) unsigned NULL,
            idempotency_key varchar(191) NOT NULL,
            reason text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            occurred_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY customer_key (customer_key),
            KEY source_order_id (source_order_id),
            KEY occurred_at (occurred_at),
            KEY reversal_of_id (reversal_of_id)
        ) {$charset};");

        // Liens d'identité : regroupe les clés d'un même client (e-mail(s) + téléphone(s))
        // sous une clé canonique, pour agréger la fidélité même quand deux commandes ne
        // partagent aucun champ de contact. Alimenté automatiquement (co-occurrence
        // e-mail+téléphone sur une commande) et par fusion manuelle depuis la fiche.
        $links = $this->identityLinksTableName();
        dbDelta("CREATE TABLE {$links} (
            identity_key char(20) NOT NULL,
            canonical_key char(20) NOT NULL,
            linked_at datetime NOT NULL,
            PRIMARY KEY  (identity_key),
            KEY canonical_key (canonical_key)
        ) {$charset};");

        update_option(self::OPTION, self::VERSION, false);
    }

    public function ledgerTableName(): string
    {
        return $this->database->prefix . 'luziapi_loyalty_ledger';
    }

    public function identityLinksTableName(): string
    {
        return $this->database->prefix . 'luziapi_loyalty_identity_links';
    }
}
