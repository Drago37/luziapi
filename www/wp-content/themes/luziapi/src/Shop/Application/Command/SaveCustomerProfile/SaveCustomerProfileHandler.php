<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\SaveCustomerProfile;

use InvalidArgumentException;
use LuziApi\Shop\Application\Port\Clock;
use LuziApi\Shop\Domain\Customer\CustomerProfileRepository;

final readonly class SaveCustomerProfileHandler
{
    public function __construct(
        private CustomerProfileRepository $profiles,
        private Clock $clock,
    ) {
    }

    public function handle(SaveCustomerProfileCommand $command): void
    {
        if ([] === $command->customerIds) {
            throw new InvalidArgumentException('Aucune identité client à mettre à jour.');
        }
        foreach ($command->customerIds as $customerId) {
            if (1 !== preg_match('/^[a-f0-9]{20}$/', $customerId)) {
                throw new InvalidArgumentException('Identité client invalide.');
            }
        }
        $email = $command->billing->email;
        if ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }

        $this->profiles->save(
            $command->customerIds,
            $command->billing,
            $command->actorId,
            $this->clock->now(),
        );
    }
}
