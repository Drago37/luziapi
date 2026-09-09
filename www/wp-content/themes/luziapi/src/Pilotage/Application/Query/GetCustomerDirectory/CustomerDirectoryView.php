<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetCustomerDirectory;

use LuziApi\Pilotage\Domain\Customer\CustomerProfile;

final readonly class CustomerDirectoryView
{
    /**
     * @param list<CustomerProfile> $customers
     */
    public function __construct(
        public array $customers,
        public int $totalCustomers,
        public int $currentPage,
        public int $totalPages,
        public ?CustomerProfile $selectedCustomer,
    ) {
    }
}
