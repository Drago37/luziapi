<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetAnnualDashboard;

use LuziApi\Pilotage\Domain\FollowUp\FollowUpBoard;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptSummary;
use LuziApi\Pilotage\Domain\Sales\AnnualSalesSummary;

final readonly class AnnualDashboardView
{
    /**
     * @param list<int> $availableYears
     */
    public function __construct(
        public AnnualSalesSummary $summary,
        public array $availableYears,
        public FollowUpBoard $followUp,
        public AnnualReceiptSummary $receipts,
    ) {
    }
}
