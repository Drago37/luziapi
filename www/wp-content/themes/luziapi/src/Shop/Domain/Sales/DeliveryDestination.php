<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Sales;

/**
 * Destination d'expédition d'une commande et règle métier de la livraison
 * gratuite locale.
 *
 * La livraison gratuite est strictement réservée à Bléré et Luzillé : le code
 * postal seul ne suffit pas, car plusieurs communes partagent le 37150. La
 * comparaison de la ville ignore accents, casse et séparateurs.
 */
final readonly class DeliveryDestination
{
    private const FREE_DELIVERY_COUNTRY = 'FR';
    private const FREE_DELIVERY_POSTCODE = '37150';

    /** @var list<string> villes normalisées éligibles à la livraison gratuite */
    private const FREE_DELIVERY_CITIES = ['blere', 'luzille'];

    private const ACCENT_FOLDING = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    public function __construct(
        private string $country,
        private string $postcode,
        private string $city,
    ) {
    }

    public function qualifiesForFreeDelivery(): bool
    {
        return self::FREE_DELIVERY_COUNTRY === strtoupper($this->country)
            && self::FREE_DELIVERY_POSTCODE === (string) preg_replace('/\s+/', '', $this->postcode)
            && in_array(self::normalizeCity($this->city), self::FREE_DELIVERY_CITIES, true);
    }

    /**
     * Normalise une ville saisie librement (minuscules, accents repliés,
     * caractères non alphabétiques retirés) pour une comparaison stable.
     */
    public static function normalizeCity(string $city): string
    {
        $lowered = mb_strtolower($city, 'UTF-8');
        $folded = strtr($lowered, self::ACCENT_FOLDING);

        return (string) preg_replace('/[^a-z]/', '', $folded);
    }
}
