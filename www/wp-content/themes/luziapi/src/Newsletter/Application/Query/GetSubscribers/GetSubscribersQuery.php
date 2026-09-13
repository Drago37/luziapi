<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Query\GetSubscribers;

final readonly class GetSubscribersQuery
{
    public function __construct(
        public string $search = '',
    ) {
    }
}
