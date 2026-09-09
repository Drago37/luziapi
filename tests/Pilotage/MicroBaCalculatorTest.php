<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use LuziApi\Pilotage\Domain\Shared\Money;
use LuziApi\Pilotage\Domain\Tax\MicroBaCalculator;
use PHPUnit\Framework\TestCase;

final class MicroBaCalculatorTest extends TestCase
{
    public function testItCalculatesTheThreeYearAverageAndEightySevenPercentAllowance(): void
    {
        $estimate = (new MicroBaCalculator())->calculate(2026, 2020, [
            2024 => new Money(1_000_000),
            2025 => new Money(1_200_000),
            2026 => new Money(1_400_000),
        ]);

        self::assertSame(3, $estimate->yearsCount);
        self::assertSame(1_200_000, $estimate->averageReceipts->cents());
        self::assertSame(1_044_000, $estimate->allowance->cents());
        self::assertSame(156_000, $estimate->taxableProfit->cents());
    }

    public function testItUsesOnlyAvailableYearsAtTheBeginningOfActivity(): void
    {
        $estimate = (new MicroBaCalculator())->calculate(2026, 2025, [
            2025 => new Money(100_000),
            2026 => new Money(300_000),
        ]);

        self::assertSame(2, $estimate->yearsCount);
        self::assertSame(200_000, $estimate->averageReceipts->cents());
        self::assertSame(26_000, $estimate->taxableProfit->cents());
    }

    public function testMinimumAllowanceCannotCreateANegativeTaxableProfit(): void
    {
        $estimate = (new MicroBaCalculator())->calculate(2026, 2026, [2026 => new Money(20_000)]);

        self::assertSame(20_000, $estimate->allowance->cents());
        self::assertSame(0, $estimate->taxableProfit->cents());
    }
}
