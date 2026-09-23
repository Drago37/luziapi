<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\AssignCustomerCategory;

use InvalidArgumentException;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Domain\Customer\CustomerCategoryRepository;

final readonly class AssignCustomerCategoryHandler
{
    public function __construct(
        private CustomerCategoryRepository $categories,
        private Clock $clock,
    ) {
    }

    public function handle(AssignCustomerCategoryCommand $command): void
    {
        if ([] === $command->customerIds) {
            throw new InvalidArgumentException('Customer identifiers cannot be empty.');
        }
        foreach ($command->customerIds as $customerId) {
            if (1 !== preg_match('/^[a-f0-9]{20}$/', $customerId)) {
                throw new InvalidArgumentException('Invalid customer identifier.');
            }
        }

        $this->categories->assign(
            $command->customerIds,
            $command->category,
            $command->actorId,
            $this->clock->now(),
        );
    }
}
