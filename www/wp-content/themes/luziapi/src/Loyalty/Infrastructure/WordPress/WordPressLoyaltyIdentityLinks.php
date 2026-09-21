<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use LuziApi\Loyalty\Domain\Gateway\LoyaltyIdentityLinks;
use LuziApi\Shared\Infrastructure\Wp;
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
        $canonicals = $this->database->get_col(Wp::prepared(
            $this->database,
            "SELECT DISTINCT canonical_key FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$keys,
        ));
        $canonicals = array_map(static fn ($value): string => Wp::str($value), is_array($canonicals) ? $canonicals : []);
        if ([] === $canonicals) {
            return $keys;
        }

        $canonicalPlaceholders = implode(', ', array_fill(0, count($canonicals), '%s'));
        $group = $this->database->get_col(Wp::prepared(
            $this->database,
            "SELECT identity_key FROM {$table} WHERE canonical_key IN ({$canonicalPlaceholders})",
            ...$canonicals,
        ));
        $group = array_map(static fn ($value): string => Wp::str($value), is_array($group) ? $group : []);

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
        $rows = $this->database->get_results(Wp::prepared(
            $this->database,
            "SELECT identity_key, canonical_key FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$keys,
        ), ARRAY_A);

        $existingCanonicals = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $existingCanonicals[Wp::str($row['canonical_key'])] = true;
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
            $this->database->query(Wp::prepared(
                $this->database,
                "UPDATE {$table} SET canonical_key = %s WHERE canonical_key IN ({$canonicalPlaceholders})",
                ...array_merge([$merged], $canonicalKeys),
            ));
        }

        // Chaque clé fournie porte désormais une ligne vers la canonique fusionnée.
        foreach ($keys as $key) {
            $this->database->query(Wp::prepared(
                $this->database,
                "INSERT INTO {$table} (identity_key, canonical_key, linked_at) VALUES (%s, %s, %s)"
                . ' ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key)',
                $key,
                $merged,
                $now,
            ));
        }
    }

    public function autoLink(string $emailKey, string $phoneKey): void
    {
        if (! $this->isValidKey($emailKey) || ! $this->isValidKey($phoneKey)) {
            return;
        }
        // Téléphone déjà rattaché : on ne l'utilise pas pour absorber un 2ᵉ e-mail
        // (téléphone de foyer / partagé). Le cas ambigu relève de la fusion manuelle.
        if ($this->isLinked($phoneKey)) {
            return;
        }

        $this->union([$emailKey, $phoneKey]);
    }

    public function unlink(array $keys): void
    {
        $detach = $this->sanitize($keys);
        if ([] === $detach) {
            return;
        }

        $table = $this->schema->identityLinksTableName();
        $placeholders = implode(', ', array_fill(0, count($detach), '%s'));
        $canonicals = $this->database->get_col(Wp::prepared(
            $this->database,
            "SELECT DISTINCT canonical_key FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$detach,
        ));
        $canonicals = array_map(static fn ($value): string => Wp::str($value), is_array($canonicals) ? $canonicals : []);
        if ([] === $canonicals) {
            return; // aucune de ces clés n'est liée
        }

        $canonicalPlaceholders = implode(', ', array_fill(0, count($canonicals), '%s'));
        $all = $this->database->get_col(Wp::prepared(
            $this->database,
            "SELECT identity_key FROM {$table} WHERE canonical_key IN ({$canonicalPlaceholders})",
            ...$canonicals,
        ));
        $all = array_map(static fn ($value): string => Wp::str($value), is_array($all) ? $all : []);

        $detachSet = array_fill_keys($detach, true);
        $remaining = array_values(array_filter($all, static fn (string $key): bool => ! isset($detachSet[$key])));

        // Deux groupes séparés : les clés détachées d'un côté, le reste de l'autre.
        // Transaction : le DELETE+ré-INSERT des deux repointages est atomique, pour ne
        // jamais laisser un groupe à moitié scindé en cas d'échec en cours de route.
        $this->transactional(function () use ($detach, $remaining): void {
            $this->repoint($detach);
            $this->repoint($remaining);
        });
    }

    /**
     * Exécute des écritures dans une transaction, avec rollback si une exception est
     * levée. Sur MySQL/InnoDB (tables WP), garantit l'atomicité d'une séquence.
     */
    private function transactional(\Closure $writes): void
    {
        $this->database->query('START TRANSACTION');
        try {
            $writes();
            $this->database->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');

            throw $exception;
        }
    }

    /**
     * Reforme un groupe isolé à partir de ces clés (canonique = la plus petite). Un
     * singleton redevient une clé libre (aucune ligne). Casse d'abord tout ancien lien.
     *
     * @param list<string> $keys
     */
    private function repoint(array $keys): void
    {
        if ([] === $keys) {
            return;
        }

        $table = $this->schema->identityLinksTableName();
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $this->database->query(Wp::prepared(
            $this->database,
            "DELETE FROM {$table} WHERE identity_key IN ({$placeholders})",
            ...$keys,
        ));
        if (count($keys) < 2) {
            return; // une clé seule n'a pas besoin de ligne : elle redevient libre
        }

        sort($keys);
        $merged = $keys[0];
        $now = current_time('mysql');
        foreach ($keys as $key) {
            $this->database->query(Wp::prepared(
                $this->database,
                "INSERT INTO {$table} (identity_key, canonical_key, linked_at) VALUES (%s, %s, %s)"
                . ' ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key)',
                $key,
                $merged,
                $now,
            ));
        }
    }

    private function isValidKey(string $key): bool
    {
        return 1 === preg_match('/^[0-9a-f]{20}$/', $key);
    }

    private function isLinked(string $key): bool
    {
        $table = $this->schema->identityLinksTableName();

        return null !== $this->database->get_var(Wp::prepared(
            $this->database,
            "SELECT identity_key FROM {$table} WHERE identity_key = %s LIMIT 1",
            $key,
        ));
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
            if ($this->isValidKey($key)) {
                $clean[$key] = true;
            }
        }

        return array_keys($clean);
    }
}
