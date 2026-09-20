<?php

declare(strict_types=1);

namespace LuziApi\Support;

use wpdb;

/**
 * Conversions sûres des valeurs `mixed` renvoyées par WordPress/WooCommerce
 * (`wpdb`, `get_meta()`, superglobales…) vers des types stricts, sans changer le
 * comportement : les colonnes SQL et métas de ce thème contiennent des scalaires
 * propres (ou `null`), donc ces conversions sont équivalentes aux casts directs
 * tout en satisfaisant PHPStan au niveau max.
 */
final class Wp
{
    /** Entier depuis une valeur `mixed` (chaîne numérique / null → 0). */
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Chaîne depuis une valeur `mixed` (non scalaire → chaîne vide). */
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Ligne SQL associative (non tableau → tableau vide).
     *
     * @return array<string, mixed>
     */
    public static function row(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $row = [];
        foreach ($value as $key => $cell) {
            $row[(string) $key] = $cell;
        }

        return $row;
    }

    /**
     * Liste de lignes SQL associatives (filtre le non-tableau).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = self::row($row);
            }
        }

        return $rows;
    }

    /**
     * `wpdb::prepare()` avec un SQL contenant un nom de table interne (constante de
     * schéma, jamais une entrée utilisateur) : ce SQL n'est pas une `literal-string`
     * au sens de PHPStan mais reste sûr. Centralise l'unique dérogation et renvoie
     * toujours une chaîne exploitable par `get_row()` / `get_results()` / `get_var()`.
     */
    public static function prepared(wpdb $database, string $sql, mixed ...$args): string
    {
        /** @phpstan-ignore argument.type (table name is a trusted internal schema constant, not user input) */
        $prepared = $database->prepare($sql, ...$args);

        return is_string($prepared) ? $prepared : '';
    }
}
