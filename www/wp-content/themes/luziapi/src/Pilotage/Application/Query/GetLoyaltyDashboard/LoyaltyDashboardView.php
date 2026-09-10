<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

/**
 * Vue du tableau de bord de fidélité pour une année : récapitulatif par client et
 * classements.
 */
final readonly class LoyaltyDashboardView
{
    /**
     * @param list<LoyaltyCustomerRow> $customers     clients avec activité fidélité dans l'année, triés par pots achetés
     * @param list<LoyaltyCustomerRow> $topBuyers     meilleurs clients par pots achetés
     * @param list<LoyaltyCustomerRow> $topBenefited  clients ayant reçu le plus de pots offerts
     * @param list<LoyaltyCustomerRow> $topDiscounts  clients ayant reçu le plus de remise remerciement
     * @param list<int>                $availableYears années proposées au sélecteur, décroissantes
     */
    public function __construct(
        public int $year,
        public array $availableYears,
        public array $customers,
        public array $topBuyers,
        public array $topBenefited,
        public array $topDiscounts,
        public int $totalCustomers,
        public int $totalPots,
        public int $totalOfferedPots,
        public int $totalDiscountCents,
    ) {
    }

    /**
     * @param list<int> $availableYears
     */
    public static function empty(int $year, array $availableYears): self
    {
        return new self($year, $availableYears, [], [], [], [], 0, 0, 0, 0);
    }
}
