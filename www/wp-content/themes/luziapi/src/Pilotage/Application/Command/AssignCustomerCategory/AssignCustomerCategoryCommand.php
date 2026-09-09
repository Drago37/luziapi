<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\AssignCustomerCategory;

use LuziApi\Pilotage\Domain\Customer\CustomerCategory;

final readonly class AssignCustomerCategoryCommand
{
    public function __construct(
        /** @var non-empty-list<string> */
        public array $customerIds,
        public CustomerCategory $category,
        public int $actorId,
    ) {
    }
}
