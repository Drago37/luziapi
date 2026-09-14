<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\SearchAddress;

final readonly class SearchAddressQuery
{
    public function __construct(
        public string $query,
        public int $limit = 5,
    ) {
    }
}
