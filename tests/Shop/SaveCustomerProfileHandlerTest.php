<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LuziApi\Shop\Application\Command\SaveCustomerProfile\SaveCustomerProfileCommand;
use LuziApi\Shop\Application\Command\SaveCustomerProfile\SaveCustomerProfileHandler;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Domain\Customer\CustomerBilling;
use LuziApi\Shop\Domain\Customer\CustomerProfileRepository;
use PHPUnit\Framework\TestCase;

final class SaveCustomerProfileHandlerTest extends TestCase
{
    public function testItSavesTheProfileOnEveryIdentityOfTheCustomer(): void
    {
        $repository = new CustomerProfileRepositoryInMemory();
        $handler = new SaveCustomerProfileHandler($repository, new CustomerProfileClock());

        $billing = new CustomerBilling('Hélène', 'Dupont', '', '3 rue des Abeilles', '', '37150', 'Luzillé', 'FR', 'helene@example.test', '06 31 43 70 46');
        $handler->handle(new SaveCustomerProfileCommand(
            ['0123456789abcdefabcd', 'abcdefabcd0123456789'],
            $billing,
            7,
        ));

        self::assertSame('Hélène', $repository->forCustomerIds(['0123456789abcdefabcd'])['0123456789abcdefabcd']->firstName);
        self::assertSame('3 rue des Abeilles', $repository->forCustomerIds(['abcdefabcd0123456789'])['abcdefabcd0123456789']->address1);
        self::assertSame(7, $repository->lastActorId);
        self::assertSame('2026-09-09 18:00:00', $repository->lastUpdatedAt?->format('Y-m-d H:i:s'));
    }

    public function testItRejectsAnUnknownCustomerIdentifierFormat(): void
    {
        $repository = new CustomerProfileRepositoryInMemory();
        $handler = new SaveCustomerProfileHandler($repository, new CustomerProfileClock());

        $this->expectException(InvalidArgumentException::class);
        try {
            $handler->handle(new SaveCustomerProfileCommand(['not-a-customer-id'], new CustomerBilling(), 7));
        } finally {
            self::assertSame([], $repository->forCustomerIds(['not-a-customer-id']));
        }
    }

    public function testItRejectsAnInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SaveCustomerProfileHandler(new CustomerProfileRepositoryInMemory(), new CustomerProfileClock()))
            ->handle(new SaveCustomerProfileCommand(['0123456789abcdefabcd'], new CustomerBilling(email: 'not-an-email'), 7));
    }

    public function testItAllowsAnEmptyProfileToResetTheOverride(): void
    {
        // Une fiche entièrement vide est valide : elle efface la surcharge et
        // l'affichage retombe sur les commandes.
        $repository = new CustomerProfileRepositoryInMemory();
        (new SaveCustomerProfileHandler($repository, new CustomerProfileClock()))
            ->handle(new SaveCustomerProfileCommand(['0123456789abcdefabcd'], new CustomerBilling(), 7));

        self::assertTrue($repository->forCustomerIds(['0123456789abcdefabcd'])['0123456789abcdefabcd']->isEmpty());
    }
}

final class CustomerProfileClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09 18:00:00', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}

final class CustomerProfileRepositoryInMemory implements CustomerProfileRepository
{
    /** @var array<string, CustomerBilling> */
    private array $profiles = [];
    public int $lastActorId = 0;
    public ?DateTimeImmutable $lastUpdatedAt = null;

    public function forCustomerIds(array $customerIds): array
    {
        return array_intersect_key($this->profiles, array_flip($customerIds));
    }

    public function save(array $customerIds, CustomerBilling $billing, int $actorId, DateTimeImmutable $updatedAt): void
    {
        foreach ($customerIds as $customerId) {
            $this->profiles[$customerId] = $billing;
        }
        $this->lastActorId = $actorId;
        $this->lastUpdatedAt = $updatedAt;
    }
}
