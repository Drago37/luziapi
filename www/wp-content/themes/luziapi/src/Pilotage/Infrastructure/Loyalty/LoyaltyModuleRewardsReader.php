<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\Loyalty;

use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Pilotage\Application\Port\LoyaltyRewardsReader;

/**
 * Adaptateur du port {@see LoyaltyRewardsReader} vers le module Loyalty. Passe-plat
 * vers `GetCustomerLoyaltyHandler::availableRewardsByCustomer()`.
 */
final readonly class LoyaltyModuleRewardsReader implements LoyaltyRewardsReader
{
    public function __construct(
        private GetCustomerLoyaltyHandler $loyalty,
    ) {
    }

    public function availableRewardsByCustomer(array $keysByCustomer): array
    {
        return $this->loyalty->availableRewardsByCustomer($keysByCustomer);
    }
}
