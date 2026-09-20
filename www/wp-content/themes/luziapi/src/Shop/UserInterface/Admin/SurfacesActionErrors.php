<?php

declare(strict_types=1);

namespace LuziApi\Shop\UserInterface\Admin;

use LuziApi\Support\Wp;
use Throwable;

/**
 * Rend visible la cause réelle d'une action d'administration échouée.
 *
 * Les actions du pilotage attrapent leurs exceptions puis redirigent vers un
 * message générique. Ce trait met de côté (transient éphémère, par utilisateur
 * et par écran) le message d'exception formaté, que le `render()` récupère pour
 * l'afficher sous le message d'erreur — plus de « trou noir » au diagnostic.
 */
trait SurfacesActionErrors
{
    private function rememberErrorDetail(string $scope, Throwable $exception): void
    {
        set_transient(
            $this->errorTransientKey($scope),
            ErrorDetailFormatter::format($exception),
            120,
        );
    }

    /**
     * Met de côté un détail de message (succès comme échec), récupéré par `render()`
     * via {@see takeErrorDetail()} — même canal, exclusif dans une même requête.
     */
    private function rememberDetail(string $scope, string $text): void
    {
        set_transient($this->errorTransientKey($scope), $text, 120);
    }

    private function takeErrorDetail(string $scope): string
    {
        $key = $this->errorTransientKey($scope);
        $detail = Wp::str(get_transient($key) ?: '');
        if ('' !== $detail) {
            delete_transient($key);
        }

        return $detail;
    }

    private function errorTransientKey(string $scope): string
    {
        return 'luziapi_pilotage_error_' . $scope . '_' . get_current_user_id();
    }
}
