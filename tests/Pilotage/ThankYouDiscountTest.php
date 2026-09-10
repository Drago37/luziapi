<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use InvalidArgumentException;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscountType;
use PHPUnit\Framework\TestCase;

final class ThankYouDiscountTest extends TestCase
{
    public function testPercentageDiscountOfABase(): void
    {
        $discount = ThankYouDiscount::percent(10);

        self::assertSame(ThankYouDiscountType::Percent, $discount->type);
        self::assertSame(240, $discount->computeCents(2_400)); // 10 % de 24,00 €
    }

    public function testAmountDiscountIsCappedToTheBase(): void
    {
        $discount = ThankYouDiscount::amount(5_000);

        self::assertSame(2_400, $discount->computeCents(2_400)); // borné au total
    }

    public function testDiscountOnAZeroBaseIsZero(): void
    {
        self::assertSame(0, ThankYouDiscount::percent(50)->computeCents(0));
        self::assertSame(0, ThankYouDiscount::amount(100)->computeCents(0));
    }

    public function testPercentageBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThankYouDiscount::percent(0);
    }

    public function testPercentageUpperBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThankYouDiscount::percent(101);
    }

    public function testFromInputReturnsNullWhenEmptyOrZero(): void
    {
        self::assertNull(ThankYouDiscount::fromInput('', 100));
        self::assertNull(ThankYouDiscount::fromInput('percent', 0));
        self::assertNull(ThankYouDiscount::fromInput('amount', 0));
    }

    public function testFromInputBuildsEachType(): void
    {
        self::assertSame(ThankYouDiscountType::Percent, ThankYouDiscount::fromInput('percent', 15)?->type);
        self::assertSame(ThankYouDiscountType::Amount, ThankYouDiscount::fromInput('amount', 500)?->type);
    }
}
