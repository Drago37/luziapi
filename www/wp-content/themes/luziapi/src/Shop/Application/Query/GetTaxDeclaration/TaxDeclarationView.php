<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetTaxDeclaration;

use LuziApi\Shop\Domain\Tax\MicroBaEstimate;

final readonly class TaxDeclarationView
{
    /** @param list<int> $availableYears */
    public function __construct(
        public MicroBaEstimate $estimate,
        public array $availableYears,
        public int $activityStartYear,
    ) {
    }
}
