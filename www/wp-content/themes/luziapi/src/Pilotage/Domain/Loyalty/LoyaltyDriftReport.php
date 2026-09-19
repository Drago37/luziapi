<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Loyalty;

/**
 * Bilan de la dérive du programme de fidélité sur une période. Deux familles
 * d'anomalie : commande admissible « Terminée » jamais créditée (trou de crédit),
 * et écriture rattachée à une commande disparue mais encore positive (orpheline).
 */
final readonly class LoyaltyDriftReport
{
    /**
     * @param list<LoyaltyCreditGap>    $creditGaps    commandes admissibles non créditées
     * @param list<OrphanLoyaltyCredit> $orphanCredits crédits rattachés à une commande disparue
     */
    public function __construct(
        public array $creditGaps,
        public array $orphanCredits,
    ) {
    }

    public function hasDrift(): bool
    {
        return [] !== $this->creditGaps || [] !== $this->orphanCredits;
    }

    public function anomalyCount(): int
    {
        return count($this->creditGaps) + count($this->orphanCredits);
    }

    /** Pots admissibles non crédités, tous trous confondus. */
    public function totalMissingPots(): int
    {
        $total = 0;
        foreach ($this->creditGaps as $gap) {
            $total += $gap->eligiblePots;
        }

        return $total;
    }

    /** Pots encore crédités à tort à des commandes disparues. */
    public function totalOrphanPots(): int
    {
        $total = 0;
        foreach ($this->orphanCredits as $orphan) {
            $total += $orphan->netPots;
        }

        return $total;
    }
}
