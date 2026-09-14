<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Address;

/**
 * Recherche d'adresses pour l'autocomplétion. Résilient par conception : toute
 * indisponibilité (réseau, réponse inattendue) rend une liste vide plutôt que de
 * casser la saisie — l'utilisateur peut toujours renseigner l'adresse à la main.
 */
interface AddressLookup
{
    /**
     * @return list<AddressSuggestion>
     */
    public function search(string $query, int $limit): array;
}
