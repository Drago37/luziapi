<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Address;

/**
 * Une adresse proposée par un service d'autocomplétion (Base Adresse Nationale).
 * `street` = numéro + voie (à mettre dans « Adresse »), avec code postal et ville
 * normalisés ; `label` est l'affichage complet de la suggestion.
 */
final readonly class AddressSuggestion
{
    public function __construct(
        public string $label,
        public string $street,
        public string $postcode,
        public string $city,
    ) {
    }
}
