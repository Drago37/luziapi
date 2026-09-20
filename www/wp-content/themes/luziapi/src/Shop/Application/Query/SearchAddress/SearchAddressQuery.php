<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\SearchAddress;

final readonly class SearchAddressQuery
{
    public function __construct(
        public string $query,
        public int $limit = 10,
    ) {
    }
}
