<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use LuziApi\Pilotage\Domain\Sales\VolumeDiscount;
use PHPUnit\Framework\TestCase;

final class VolumeDiscountTest extends TestCase
{
    public function testNoDiscountBelowTwoJars(): void
    {
        self::assertSame(0, VolumeDiscount::cents(0));
        self::assertSame(0, VolumeDiscount::cents(1));
    }

    public function testOneEuroPerJarFromTwoJars(): void
    {
        self::assertSame(200, VolumeDiscount::cents(2));
        self::assertSame(300, VolumeDiscount::cents(3));
        // Le cas prod : 5 pots (3 + 2 miels) => 5 €.
        self::assertSame(500, VolumeDiscount::cents(5));
    }

    public function testEurosIsTheSharedConversion(): void
    {
        self::assertSame(0, VolumeDiscount::euros(1));
        self::assertSame(2, VolumeDiscount::euros(2));
        self::assertSame(5, VolumeDiscount::euros(5));
    }

    public function testLabelStatesTheCount(): void
    {
        self::assertSame('Remise (−1 € par pot dès 2 pots) × 5', VolumeDiscount::label(5));
    }
}
