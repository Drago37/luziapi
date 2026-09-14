<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\UpdateCustomerContact;

final readonly class UpdateCustomerContactCommand
{
    /**
     * @param list<int> $orderIds commandes du client à mettre à jour
     */
    public function __construct(
        public array $orderIds,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public string $city,
    ) {
    }
}
