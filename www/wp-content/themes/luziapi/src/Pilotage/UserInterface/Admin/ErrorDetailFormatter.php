<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use ReflectionClass;
use Throwable;

/**
 * Formate le détail d'une exception pour l'afficher sous un message d'erreur.
 *
 * Partie pure (sans WordPress) de {@see SurfacesActionErrors}, isolée pour être
 * testable : « message (ClassName @ fichier:ligne) ».
 */
final class ErrorDetailFormatter
{
    public static function format(Throwable $exception): string
    {
        return sprintf(
            '%s (%s @ %s:%d)',
            $exception->getMessage(),
            (new ReflectionClass($exception))->getShortName(),
            basename($exception->getFile()),
            $exception->getLine(),
        );
    }
}
