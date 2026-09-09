<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\AssignCustomerCategory\AssignCustomerCategoryCommand;
use LuziApi\Pilotage\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Customer\CustomerCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategoryRepository;
use PHPUnit\Framework\TestCase;

final class AssignCustomerCategoryHandlerTest extends TestCase
{
    public function testTheAllowedCategoriesStaySimpleAndWithoutSubcategories(): void
    {
        self::assertSame(
            ['Non renseigné', 'Particulier', 'Professionnel', 'Association', 'Collectivité', 'Autre'],
            array_map(static fn (CustomerCategory $category): string => $category->label(), CustomerCategory::cases()),
        );
    }

    public function testItAssignsAValidatedCategoryToTheStableCustomerIdentifier(): void
    {
        $repository = new CustomerCategoryRepositoryInMemory();
        $handler = new AssignCustomerCategoryHandler($repository, new CustomerCategoryClock());

        $handler->handle(new AssignCustomerCategoryCommand(
            ['0123456789abcdefabcd', 'abcdefabcd0123456789'],
            CustomerCategory::Professional,
            7,
        ));

        self::assertSame(
            CustomerCategory::Professional,
            $repository->forCustomerIds(['0123456789abcdefabcd'])['0123456789abcdefabcd'],
        );
        self::assertSame(
            CustomerCategory::Professional,
            $repository->forCustomerIds(['abcdefabcd0123456789'])['abcdefabcd0123456789'],
        );
        self::assertSame(7, $repository->lastActorId);
        self::assertSame('2026-09-09 18:00:00', $repository->lastUpdatedAt?->format('Y-m-d H:i:s'));
    }

    public function testItRejectsAnUnknownCustomerIdentifierFormat(): void
    {
        $repository = new CustomerCategoryRepositoryInMemory();
        $handler = new AssignCustomerCategoryHandler($repository, new CustomerCategoryClock());

        $this->expectException(InvalidArgumentException::class);
        try {
            $handler->handle(new AssignCustomerCategoryCommand(
                ['0123456789abcdefabcd', 'not-a-customer-id'],
                CustomerCategory::Other,
                7,
            ));
        } finally {
            self::assertSame([], $repository->forCustomerIds(['0123456789abcdefabcd']));
        }
    }
}

final class CustomerCategoryClock implements Clock
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

final class CustomerCategoryRepositoryInMemory implements CustomerCategoryRepository
{
    /** @var array<string, CustomerCategory> */
    private array $categories = [];
    public int $lastActorId = 0;
    public ?DateTimeImmutable $lastUpdatedAt = null;

    public function forCustomerIds(array $customerIds): array
    {
        return array_intersect_key($this->categories, array_flip($customerIds));
    }

    public function assign(
        array $customerIds,
        CustomerCategory $category,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void {
        foreach ($customerIds as $customerId) {
            $this->categories[$customerId] = $category;
        }
        $this->lastActorId = $actorId;
        $this->lastUpdatedAt = $updatedAt;
    }
}
