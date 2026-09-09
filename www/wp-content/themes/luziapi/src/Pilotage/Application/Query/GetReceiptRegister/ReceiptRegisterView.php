<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetReceiptRegister;

use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptSummary;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliation;

final readonly class ReceiptRegisterView
{
    /**
     * @param list<int>                   $availableYears
     * @param list<ReceiptReconciliation> $reconciliations
     */
    public function __construct(
        public AnnualReceiptSummary $summary,
        public array $availableYears,
        public array $reconciliations,
    ) {
    }
}
