<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

/**
 * Vue du tableau de bord de fidélité : récapitulatif par client et classements.
 */
final readonly class LoyaltyDashboardView
{
    /**
     * @param list<LoyaltyCustomerRow> $customers     tous les clients avec activité fidélité, triés par pots achetés
     * @param list<LoyaltyCustomerRow> $topBuyers     meilleurs clients par pots achetés
     * @param list<LoyaltyCustomerRow> $topBenefited  clients ayant reçu le plus de pots offerts
     * @param list<LoyaltyCustomerRow> $topDiscounts  clients ayant reçu le plus de remise remerciement
     */
    public function __construct(
        public array $customers,
        public array $topBuyers,
        public array $topBenefited,
        public array $topDiscounts,
        public int $totalCustomers,
        public int $totalPots,
        public int $totalOfferedPots,
        public int $totalDiscountCents,
        public int $totalRewardsAvailable,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], 0, 0, 0, 0, 0);
    }
}
