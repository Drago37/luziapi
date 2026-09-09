<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetTaxDeclaration;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\TaxSettings;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Tax\MicroBaCalculator;

final readonly class GetTaxDeclarationHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private OrderRepository $orders,
        private AnnualReceiptCalculator $receiptCalculator,
        private MicroBaCalculator $microBaCalculator,
        private TaxSettings $settings,
        private Clock $clock,
    ) {
    }

    public function handle(?int $requestedYear): TaxDeclarationView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $year = max(2000, min($requestedYear ?? $currentYear, $currentYear + 1));
        $firstOrder = $this->orders->firstOrderDate();
        $fallbackStart = $firstOrder ? (int) $firstOrder->format('Y') : $currentYear;
        $activityStartYear = min($year, $this->settings->activityStartYear($fallbackStart));
        $firstReferenceYear = max($activityStartYear, $year - 2);
        $annualReceipts = [];

        for ($referenceYear = $firstReferenceYear; $referenceYear <= $year; ++$referenceYear) {
            $start = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $referenceYear), $this->clock->timezone());
            $end = new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $referenceYear), $this->clock->timezone());
            $annualReceipts[$referenceYear] = $this->receiptCalculator
                ->calculate($referenceYear, $this->receipts->occurredBetween($start, $end))
                ->net;
        }

        $availableYears = range($currentYear, max(2000, min($activityStartYear, $currentYear)));
        if (! in_array($year, $availableYears, true)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }

        return new TaxDeclarationView(
            $this->microBaCalculator->calculate($year, $activityStartYear, $annualReceipts),
            $availableYears,
            $activityStartYear,
        );
    }
}
