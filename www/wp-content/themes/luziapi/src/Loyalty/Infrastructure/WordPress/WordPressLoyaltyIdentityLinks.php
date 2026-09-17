<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use LuziApi\Loyalty\Application\Port\LoyaltyIdentityLinks;
use wpdb;

/**
 * Liens d'identité en table (`…luziapi_loyalty_identity_links`). Chaque clé pointe
 * vers une clé canonique ; toutes les clés d'un même client partagent la même
 * canonique. Modèle simple (repointage direct), taillé pour un faible volume.
 */
final readonly class WordPressLoyaltyIdentityLinks implements LoyaltyIdentityLinks
{
    public function __construct(
        private wpdb $database,
        private LoyaltySchemaManager $schema,
    ) {
    }

    public function expand(array $keys): array
    {
        $keys = $this->sanitize($keys);
        if ([] === $keys) {
            return [];
        }

        $table = $this->schema->identityLinksTableName();
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $canonicals = $this->database->get_col($this->database->prepare(
            "SELECT DISTINCT canonical_key FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$keys,
        ));
        $canonicals = array_map(static fn ($value): string => (string) $value, is_array($canonicals) ? $canonicals : []);
        if ([] === $canonicals) {
            return $keys;
        }

        $canonicalPlaceholders = implode(', ', array_fill(0, count($canonicals), '%s'));
        $group = $this->database->get_col($this->database->prepare(
            "SELECT identity_key FROM {$table} WHERE canonical_key IN ({$canonicalPlaceholders})",
            ...$canonicals,
        ));
        $group = array_map(static fn ($value): string => (string) $value, is_array($group) ? $group : []);

        return array_values(array_unique(array_merge($keys, $group)));
    }

    public function union(array $keys): void
    {
        $keys = $this->sanitize($keys);
        if (count($keys) < 2) {
            return;
        }

        $table = $this->schema->identityLinksTableName();
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT identity_key, canonical_key FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$keys,
        ), ARRAY_A);

        $existingCanonicals = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $existingCanonicals[(string) $row['canonical_key']] = true;
        }

        // Canonique fusionnée = la plus petite clé (déterministe), parmi les clés
        // fournies et les canoniques déjà en place.
        $candidates = array_merge($keys, array_keys($existingCanonicals));
        sort($candidates);
        $merged = $candidates[0];
        $now = current_time('mysql');

        // Repointe tous les groupes existants touchés vers la canonique fusionnée.
        if ([] !== $existingCanonicals) {
            $canonicalKeys = array_keys($existingCanonicals);
            $canonicalPlaceholders = implode(', ', array_fill(0, count($canonicalKeys), '%s'));
            $this->database->query($this->database->prepare(
                "UPDATE {$table} SET canonical_key = %s WHERE canonical_key IN ({$canonicalPlaceholders})",
                ...array_merge([$merged], $canonicalKeys),
            ));
        }

        // Chaque clé fournie porte désormais une ligne vers la canonique fusionnée.
        foreach ($keys as $key) {
            $this->database->query($this->database->prepare(
                "INSERT INTO {$table} (identity_key, canonical_key, linked_at) VALUES (%s, %s, %s)"
                . ' ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key)',
                $key,
                $merged,
                $now,
            ));
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string> clés valides (20 hex), distinctes
     */
    private function sanitize(array $keys): array
    {
        $clean = [];
        foreach ($keys as $key) {
            if (1 === preg_match('/^[0-9a-f]{20}$/', $key)) {
                $clean[$key] = true;
            }
        }

        return array_keys($clean);
    }
}
