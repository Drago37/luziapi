<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetTaxDeclaration;

use LuziApi\Pilotage\Domain\Tax\MicroBaEstimate;

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
