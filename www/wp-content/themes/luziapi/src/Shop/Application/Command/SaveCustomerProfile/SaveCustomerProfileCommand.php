<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\SaveCustomerProfile;

use LuziApi\Shop\Domain\Customer\CustomerBilling;

final readonly class SaveCustomerProfileCommand
{
    /**
     * @param non-empty-list<string> $customerIds identités du client (fiche indexée par identité)
     */
    public function __construct(
        public array $customerIds,
        public CustomerBilling $billing,
        public int $actorId,
    ) {
    }
}
