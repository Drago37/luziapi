<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\UpdateCustomerContact;

use InvalidArgumentException;
use LuziApi\Pilotage\Domain\Customer\CustomerContactWriter;

final readonly class UpdateCustomerContactHandler
{
    public function __construct(private CustomerContactWriter $writer)
    {
    }

    public function handle(UpdateCustomerContactCommand $command): int
    {
        if ([] === $command->orderIds) {
            throw new InvalidArgumentException('Aucune commande à mettre à jour pour ce client.');
        }
        if ('' !== $command->email && false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }

        return $this->writer->update(
            $command->orderIds,
            $command->firstName,
            $command->lastName,
            $command->email,
            $command->phone,
            $command->city,
        );
    }
}
