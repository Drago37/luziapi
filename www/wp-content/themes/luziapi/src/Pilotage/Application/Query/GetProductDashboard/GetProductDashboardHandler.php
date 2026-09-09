<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetProductDashboard;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Product\ProductCatalog;
use LuziApi\Pilotage\Domain\Product\ProductPerformanceProjector;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;

final readonly class GetProductDashboardHandler
{
    public function __construct(
        private ProductCatalog $products,
        private OrderRepository $orders,
        private ProductPerformanceProjector $projector,
        private Clock $clock,
    ) {
    }

    public function handle(?int $requestedYear): ProductDashboardView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $year = max(2000, min($requestedYear ?? $currentYear, $currentYear + 1));
        $firstOrder = $this->orders->firstOrderDate();
        $firstYear = $firstOrder ? (int) $firstOrder->format('Y') : $currentYear;
        $availableYears = range($currentYear, max(2000, min($firstYear, $year)));
        if (! in_array($year, $availableYears, true)) {
            $availableYears[] = $year;
            rsort($availableYears);
        }
        $start = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year), $this->clock->timezone());
        $end = new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year), $this->clock->timezone());

        return new ProductDashboardView(
            $year,
            $this->projector->project($this->products->all(), $this->orders->createdBetween($start, $end)),
            $availableYears,
        );
    }
}
