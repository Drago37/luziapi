<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetLoyaltyDashboard;

/**
 * Client ayant des avantages de fidélité EN COURS : des pots offerts acquis (15
 * pots = 1 avantage) mais pas encore réclamés. Sert la liste « Pots offerts en
 * cours » du tableau de bord — indépendante de la période (les pots n'expirent pas).
 */
final readonly class LoyaltyRewardHolder
{
    public function __construct(
        public string $customerId,
        public string $name,
        public int $rewards,
    ) {
    }
}
