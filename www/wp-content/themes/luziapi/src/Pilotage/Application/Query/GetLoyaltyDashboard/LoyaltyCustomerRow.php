<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

/**
 * Ligne du récapitulatif de fidélité d'un client, pour une année donnée.
 *
 * - `potsBought` : pots achetés dans l'année (produits admissibles, commandes
 *   terminées) — sert aussi de classement des meilleurs clients ;
 * - `offeredPots` : pots offerts reçus dans l'année (geste + fidélité) ;
 * - `discountCents` : remise remerciement reçue dans l'année.
 */
final readonly class LoyaltyCustomerRow
{
    public function __construct(
        public string $customerId,
        public string $name,
        public string $city,
        public int $potsBought,
        public int $offeredPots,
        public int $discountCents,
    ) {
    }
}
