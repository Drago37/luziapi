<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLines;
use PHPUnit\Framework\TestCase;

final class QuickSaleLinesTest extends TestCase
{
    public function testOfferedIsPartOfTheQuantityAndReducesWhatIsPaid(): void
    {
        // 3 pots dont 1 offert => 2 payés + 1 offert.
        $split = QuickSaleLines::split([10 => 3], [10 => 1]);

        self::assertCount(1, $split['paid']);
        self::assertSame(10, $split['paid'][0]->productId);
        self::assertSame(2, $split['paid'][0]->quantity);
        self::assertCount(1, $split['gifts']);
        self::assertSame(10, $split['gifts'][0]->productId);
        self::assertSame(1, $split['gifts'][0]->quantity);
    }

    public function testSinglePotFullyOfferedIsFree(): void
    {
        // 1 pot dont 1 offert => 0 payé, 1 offert.
        $split = QuickSaleLines::split([10 => 1], [10 => 1]);

        self::assertSame([], $split['paid']);
        self::assertCount(1, $split['gifts']);
        self::assertSame(1, $split['gifts'][0]->quantity);
    }

    public function testWithoutGiftEverythingIsPaid(): void
    {
        $split = QuickSaleLines::split([10 => 2, 20 => 1], []);

        self::assertCount(2, $split['paid']);
        self::assertSame([], $split['gifts']);
    }

    public function testOfferedGreaterThanTotalIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuickSaleLines::split([10 => 1], [10 => 2]);
    }

    public function testOfferedWithoutMatchingQuantityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuickSaleLines::split([], [10 => 1]);
    }
}
