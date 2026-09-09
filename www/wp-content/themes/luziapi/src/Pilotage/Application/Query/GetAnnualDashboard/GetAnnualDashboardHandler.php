<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetAnnualDashboard;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\FollowUp\FollowUpProjector;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Sales\AnnualSalesCalculator;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;

final readonly class GetAnnualDashboardHandler
{
    public function __construct(
        private OrderRepository $orders,
        private AnnualSalesCalculator $calculator,
        private ReceiptRepository $receipts,
        private AnnualReceiptCalculator $receiptCalculator,
        private FollowUpProjector $followUpProjector,
        private Clock $clock,
    ) {
    }

    public function handle(GetAnnualDashboardQuery $query): AnnualDashboardView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $year = $query->year ?? $currentYear;
        $year = max(2000, min($year, $currentYear + 1));
        $timezone = $this->clock->timezone();
        $start = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year), $timezone);
        $end = new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $timezone);
        $firstOrder = $this->orders->firstOrderDate();
        $firstYear = $firstOrder ? (int) $firstOrder->format('Y') : $currentYear;
        $firstYear = min($firstYear, $year, $currentYear);
        $availableYears = range(max(2000, $currentYear), max(2000, $firstYear));

        if (! in_array($year, $availableYears, true)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }

        $orders = $this->orders->createdBetween($start, $end);
        $receiptEntries = $this->receipts->occurredBetween($start, $end);

        return new AnnualDashboardView(
            $this->calculator->calculate($year, $orders),
            $availableYears,
            $this->followUpProjector->project(
                $orders,
                $this->receipts->netTotalsByOrderIds(array_map(static fn ($order): int => $order->id, $orders)),
                $this->clock->now(),
            ),
            $this->receiptCalculator->calculate($year, $receiptEntries),
        );
    }
}
