<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetReceiptRegister;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;

final readonly class GetReceiptRegisterHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private OrderRepository $orders,
        private AnnualReceiptCalculator $calculator,
        private ReceiptReconciliationProjector $reconciliationProjector,
        private Clock $clock,
    ) {
    }

    public function handle(GetReceiptRegisterQuery $query): ReceiptRegisterView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $year = max(2000, min($query->year ?? $currentYear, $currentYear + 1));
        $timezone = $this->clock->timezone();
        $start = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year), $timezone);
        $end = new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $timezone);
        $firstOrder = $this->orders->firstOrderDate();
        $firstYear = $firstOrder ? (int) $firstOrder->format('Y') : $currentYear;
        $firstYear = min($firstYear, $year, $currentYear);
        $availableYears = range($currentYear, max(2000, $firstYear));

        if (! in_array($year, $availableYears, true)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }

        $orders = $this->orders->createdBetween($start, $end);

        return new ReceiptRegisterView(
            $this->calculator->calculate($year, $this->receipts->occurredBetween($start, $end)),
            $availableYears,
            $this->reconciliationProjector->project(
                $orders,
                $this->receipts->netTotalsByOrderIds(array_map(static fn ($order): int => $order->id, $orders)),
            ),
        );
    }
}
