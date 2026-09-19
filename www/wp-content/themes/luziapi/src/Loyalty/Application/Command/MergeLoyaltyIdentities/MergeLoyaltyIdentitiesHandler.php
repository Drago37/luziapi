<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities;

use LuziApi\Loyalty\Application\Port\LoyaltyIdentityLinks;

final readonly class MergeLoyaltyIdentitiesHandler
{
    public function __construct(
        private LoyaltyIdentityLinks $links,
    ) {
    }

    public function handle(MergeLoyaltyIdentitiesCommand $command): void
    {
        $this->links->union(array_values(array_merge($command->keysA, $command->keysB)));
    }
}
