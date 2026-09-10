<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

/**
 * Ligne du récapitulatif de fidélité d'un client.
 *
 * - `netPots` : pots achetés comptés au programme (produits admissibles, net des
 *   contre-passations) — sert aussi de classement des meilleurs clients ;
 * - `rewardsAvailable` : pots offerts disponibles à réclamer ;
 * - `offeredPots` : pots offerts déjà reçus (geste + fidélité) ;
 * - `discountCents` : remise remerciement cumulée reçue.
 */
final readonly class LoyaltyCustomerRow
{
    public function __construct(
        public string $customerId,
        public string $name,
        public string $city,
        public int $netPots,
        public int $rewardsAvailable,
        public int $offeredPots,
        public int $discountCents,
    ) {
    }
}
