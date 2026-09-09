<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetCustomerDirectory;

final readonly class GetCustomerDirectoryQuery
{
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $perPage = 50,
        public string $selectedCustomerId = '',
    ) {
    }
}
