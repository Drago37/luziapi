<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use InvalidArgumentException;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testItAddsAndSubtractsAmountsInCents(): void
    {
        $total = new Money(2_450);

        self::assertSame(3_050, $total->add(new Money(600))->cents());
        self::assertSame(2_250, $total->subtract(new Money(200))->cents());
        self::assertSame('EUR', $total->currency());
    }

    public function testItRefusesOperationsBetweenCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(100, 'EUR'))->add(new Money(100, 'USD'));
    }
}
