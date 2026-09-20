<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetAnnualDashboard;

use LuziApi\Shop\Domain\FollowUp\FollowUpBoard;
use LuziApi\Shop\Domain\Receipt\AnnualReceiptSummary;
use LuziApi\Shop\Domain\Sales\AnnualSalesSummary;

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
