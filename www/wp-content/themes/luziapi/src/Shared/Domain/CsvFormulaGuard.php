<?php

declare(strict_types=1);

namespace LuziApi\Shared\Domain;

/**
 * Neutralise l'injection de formule dans les exports CSV (« CSV injection »).
 *
 * Un tableur interprète une cellule commençant par `=`, `+`, `-`, `@` — ou par
 * un blanc de tête (`\t`, `\r`, `\n`) qui la colle à la cellule voisine — comme
 * une formule ; on la préfixe alors d'une apostrophe pour la forcer en texte.
 * Purement calculatoire, sans dépendance à WordPress.
 */
final class CsvFormulaGuard
{
    public static function neutralize(string $value): string
    {
        // Caractères de tête dangereux (recommandation OWASP « CSV injection ») :
        // les débuts de formule `= + - @` et les blancs de tête `\t \r \n` qu'un
        // tableur peut réinterpréter en collant la cellule à la précédente.
        return 1 === preg_match("/^[=+\\-@\t\r\n]/", $value) ? "'" . $value : $value;
    }
}
