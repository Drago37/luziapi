<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Receipt\AnnualReceiptCalculator;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class AnnualReceiptCalculatorTest extends TestCase
{
    public function testItSeparatesPositiveAndNegativeMovementsAndCalculatesNetTotals(): void
    {
        $entries = [
            $this->entry(1, 10_000, 'cash', '2026-01-10'),
            $this->entry(2, -2_000, 'cash', '2026-01-12', ReceiptEntryType::Refund),
            $this->entry(3, 5_000, 'wero', '2026-02-02'),
        ];

        $summary = (new AnnualReceiptCalculator())->calculate(2026, $entries);

        self::assertSame(15_000, $summary->collected->cents());
        self::assertSame(2_000, $summary->refunded->cents());
        self::assertSame(13_000, $summary->net->cents());
        self::assertSame(8_000, $summary->monthlyNetCents[1]);
        self::assertSame(5_000, $summary->monthlyNetCents[2]);
        self::assertSame(8_000, $summary->paymentMethodNetCents['cash']);
    }

    private function entry(
        int $id,
        int $amount,
        string $method,
        string $date,
        ReceiptEntryType $type = ReceiptEntryType::Collection,
    ): ReceiptEntry {
        return new ReceiptEntry(
            $id,
            $id,
            null,
            new DateTimeImmutable($date),
            new Money($amount),
            $method,
            $type,
            '',
            null,
            1,
            new DateTimeImmutable($date),
        );
    }
}
