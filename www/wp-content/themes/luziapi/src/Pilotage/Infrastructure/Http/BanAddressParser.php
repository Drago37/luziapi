<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\Http;

use LuziApi\Pilotage\Domain\Address\AddressSuggestion;

/**
 * Transforme la réponse GeoJSON de la Base Adresse Nationale en suggestions. Logique
 * PURE (aucun appel WordPress) : testable unitairement avec un corps de réponse figé.
 */
final class BanAddressParser
{
    /**
     * @return list<AddressSuggestion>
     */
    public static function fromResponseBody(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }
        $features = $decoded['features'] ?? null;
        if (! is_array($features)) {
            return [];
        }

        $suggestions = [];
        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $properties = $feature['properties'] ?? null;
            if (! is_array($properties)) {
                continue;
            }
            $str = static fn (string $key): string => isset($properties[$key]) && is_string($properties[$key]) ? $properties[$key] : '';
            $label = $str('label');
            if ('' === $label) {
                continue;
            }
            // `name` = numéro + voie ; en repli (lieu-dit, commune seule) on prend le label.
            $street = '' !== $str('name') ? $str('name') : $label;
            $suggestions[] = new AddressSuggestion($label, $street, $str('postcode'), $str('city'));
        }

        return $suggestions;
    }
}
