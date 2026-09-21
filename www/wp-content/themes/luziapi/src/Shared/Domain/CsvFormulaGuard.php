<?php

declare(strict_types=1);

namespace LuziApi\Shared\Domain;

/**
 * Neutralise l'injection de formule dans les exports CSV (« CSV injection »).
 *
 * Un tableur interprète une cellule commençant par `=`, `+`, `-` ou `@` comme
 * une formule ; on la préfixe alors d'une apostrophe pour la forcer en texte.
 * Purement calculatoire, sans dépendance à WordPress.
 */
final class CsvFormulaGuard
{
    public static function neutralize(string $value): string
    {
        return 1 === preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
