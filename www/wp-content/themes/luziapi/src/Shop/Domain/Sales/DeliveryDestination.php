<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Sales;

/**
 * Destination d'expédition d'une commande et règle métier de la livraison
 * gratuite locale.
 *
 * La livraison gratuite est strictement réservée à Bléré et Luzillé : le code
 * postal seul ne suffit pas, car plusieurs communes partagent le 37150. La
 * ville est comparée déjà normalisée (minuscules, sans accent, lettres
 * uniquement) — la normalisation reste à la charge de l'adaptateur, qui la
 * confie aux fonctions WordPress pour rester à l'identique de l'existant.
 */
final readonly class DeliveryDestination
{
    private const FREE_DELIVERY_COUNTRY = 'FR';
    private const FREE_DELIVERY_POSTCODE = '37150';

    /** @var list<string> villes normalisées éligibles à la livraison gratuite */
    private const FREE_DELIVERY_CITIES = ['blere', 'luzille'];

    public function __construct(
        private string $country,
        private string $postcode,
        private string $normalizedCity,
    ) {
    }

    public function qualifiesForFreeDelivery(): bool
    {
        return self::FREE_DELIVERY_COUNTRY === strtoupper($this->country)
            && self::FREE_DELIVERY_POSTCODE === (string) preg_replace('/\s+/', '', $this->postcode)
            && in_array($this->normalizedCity, self::FREE_DELIVERY_CITIES, true);
    }
}
