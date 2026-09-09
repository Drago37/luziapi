<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Tax;

use InvalidArgumentException;
use LuziApi\Pilotage\Domain\Shared\Money;

final class MicroBaCalculator
{
    public const ALLOWANCE_RATE = 0.87;
    public const MINIMUM_ALLOWANCE_CENTS = 30_500;

    /**
     * @param array<int, Money> $annualReceipts Recettes des années disponibles, indexées par année.
     */
    public function calculate(int $declarationYear, int $activityStartYear, array $annualReceipts): MicroBaEstimate
    {
        if ($activityStartYear > $declarationYear) {
            throw new InvalidArgumentException('Activity start year cannot be after declaration year.');
        }

        $firstYear = max($activityStartYear, $declarationYear - 2);
        $receipts = [];
        for ($year = $firstYear; $year <= $declarationYear; ++$year) {
            $receipts[$year] = $annualReceipts[$year] ?? Money::zero();
        }

        $yearsCount = count($receipts);
        $totalCents = array_sum(array_map(static fn (Money $money): int => $money->cents(), $receipts));
        $averageCents = max(0, (int) round($totalCents / max(1, $yearsCount)));
        $allowanceCents = max(
            self::MINIMUM_ALLOWANCE_CENTS,
            (int) round($averageCents * self::ALLOWANCE_RATE),
        );
        $allowanceCents = min($averageCents, $allowanceCents);

        return new MicroBaEstimate(
            $declarationYear,
            $receipts,
            $yearsCount,
            new Money($averageCents),
            new Money($allowanceCents),
            new Money(max(0, $averageCents - $allowanceCents)),
        );
    }
}
