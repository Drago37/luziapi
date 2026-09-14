<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\Http;

use LuziApi\Pilotage\Domain\Address\AddressLookup;

/**
 * Autocomplétion d'adresse adossée à la Base Adresse Nationale
 * (api-adresse.data.gouv.fr) : service public, gratuit, sans clé. Résilient par
 * conception — toute indisponibilité rend une liste vide (la saisie manuelle reste
 * possible). Le parsing est délégué à {@see BanAddressParser} (logique pure).
 */
final class BanAddressLookup implements AddressLookup
{
    private const ENDPOINT = 'https://api-adresse.data.gouv.fr/search/';

    public function search(string $query, int $limit): array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'q'     => $query,
            'limit' => $limit,
            'type'  => 'housenumber',
            'autocomplete' => 1,
        ]);

        $response = wp_remote_get($url, ['timeout' => 5, 'headers' => ['accept' => 'application/json']]);
        if ($response instanceof \WP_Error) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return [];
        }

        return BanAddressParser::fromResponseBody((string) wp_remote_retrieve_body($response));
    }
}
