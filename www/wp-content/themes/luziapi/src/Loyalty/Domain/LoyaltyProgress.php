<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

/**
 * Progression d'un client dans le programme « 15 pots achetés, le 16e offert ».
 *
 * Calcul à partir du nombre net de pots crédités (achats moins contre-passations
 * de remboursement/annulation). Chaque tranche de 15 pots ouvre un avantage :
 * 33 pots → 2 avantages acquis et 3/15 sur la tranche en cours.
 *
 * Le lot 1 ne consomme pas encore d'avantage (pot offert = lot 2) ; le nombre
 * d'avantages consommés est passé pour rester juste le jour où il deviendra non
 * nul, sans changer ce calcul.
 */
final readonly class LoyaltyProgress
{
    private const POTS_PER_REWARD = 15;

    public int $netPots;

    public int $rightsAcquired;

    public int $rightsConsumed;

    /** Avantages disponibles (acquis moins consommés), jamais négatif. */
    public int $rightsAvailable;

    /** Pots dans la tranche en cours vers le prochain avantage (0..9). */
    public int $potsTowardNextReward;

    public function __construct(int $netPots, int $rightsConsumed = 0)
    {
        $this->netPots = max(0, $netPots);
        $this->rightsAcquired = intdiv($this->netPots, self::POTS_PER_REWARD);
        $this->rightsConsumed = max(0, $rightsConsumed);
        $this->rightsAvailable = max(0, $this->rightsAcquired - $this->rightsConsumed);
        $this->potsTowardNextReward = $this->netPots % self::POTS_PER_REWARD;
    }

    /** Pots restants avant le prochain avantage (1..10). */
    public function potsUntilNextReward(): int
    {
        return self::POTS_PER_REWARD - $this->potsTowardNextReward;
    }

    public static function potsPerReward(): int
    {
        return self::POTS_PER_REWARD;
    }
}
