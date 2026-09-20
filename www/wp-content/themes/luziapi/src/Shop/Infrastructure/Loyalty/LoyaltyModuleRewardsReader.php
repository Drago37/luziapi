<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\Loyalty;

use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Shop\Domain\Gateway\LoyaltyRewardsReader;

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
