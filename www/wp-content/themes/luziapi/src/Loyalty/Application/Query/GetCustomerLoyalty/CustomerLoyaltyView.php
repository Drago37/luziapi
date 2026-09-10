<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Query\GetCustomerLoyalty;

use LuziApi\Loyalty\Domain\LoyaltyEntry;
use LuziApi\Loyalty\Domain\LoyaltyProgress;

/**
 * Vue d'affichage de la fidélité d'un client (lecture seule, prête pour Twig).
 */
final readonly class CustomerLoyaltyView
{
    /**
     * @param list<LoyaltyEntry> $entries écritures récentes, les plus récentes d'abord
     */
    public function __construct(
        public int $netPots,
        public int $rewardsAcquired,
        public int $rewardsAvailable,
        public int $potsTowardNextReward,
        public int $potsUntilNextReward,
        public int $potsPerReward,
        public array $entries,
    ) {
    }

    public static function fromProgress(LoyaltyProgress $progress, array $entries): self
    {
        return new self(
            netPots: $progress->netPots,
            rewardsAcquired: $progress->rightsAcquired,
            rewardsAvailable: $progress->rightsAvailable,
            potsTowardNextReward: $progress->potsTowardNextReward,
            potsUntilNextReward: $progress->potsUntilNextReward(),
            potsPerReward: $progress->potsPerReward(),
            entries: $entries,
        );
    }

    public static function empty(): self
    {
        return self::fromProgress(new LoyaltyProgress(0), []);
    }
}
