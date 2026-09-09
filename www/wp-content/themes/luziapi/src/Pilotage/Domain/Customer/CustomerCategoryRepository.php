<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

use DateTimeImmutable;

interface CustomerCategoryRepository
{
    /**
     * @param list<string> $customerIds
     *
     * @return array<string, CustomerCategory>
     */
    public function forCustomerIds(array $customerIds): array;

    /** @param non-empty-list<string> $customerIds */
    public function assign(
        array $customerIds,
        CustomerCategory $category,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void;
}
