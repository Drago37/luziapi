<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

/**
 * Vue du tableau de bord de fidélité pour une période : récapitulatif par client
 * et classements sur la période choisie, plus les totaux cumulés toutes années.
 */
final readonly class LoyaltyDashboardView
{
    /**
     * @param string                   $periodKey      clé de période active (`last2`, `all` ou une année)
     * @param string                   $periodLabel    libellé lisible de la période
     * @param list<int>                $availableYears années présentes, décroissantes (pour le sélecteur)
     * @param list<LoyaltyCustomerRow> $customers      clients avec activité sur la période, triés par pots achetés
     * @param list<LoyaltyCustomerRow> $topBuyers      meilleurs clients par pots achetés (période)
     * @param list<LoyaltyCustomerRow> $topBenefited   clients ayant reçu le plus de pots offerts (période)
     * @param list<LoyaltyCustomerRow> $topDiscounts   clients ayant reçu le plus de remise remerciement (période)
     */
    public function __construct(
        public string $periodKey,
        public string $periodLabel,
        public array $availableYears,
        public array $customers,
        public array $topBuyers,
        public array $topBenefited,
        public array $topDiscounts,
        public int $totalCustomers,
        public int $totalPots,
        public int $totalOfferedPots,
        public int $totalDiscountCents,
        public int $grandCustomers,
        public int $grandPots,
        public int $grandOfferedPots,
        public int $grandDiscountCents,
    ) {
    }
}
