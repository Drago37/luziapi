<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\SaveCustomerProfile;

use LuziApi\Pilotage\Domain\Customer\CustomerBilling;

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
