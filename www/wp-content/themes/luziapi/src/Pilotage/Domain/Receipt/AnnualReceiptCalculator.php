<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

use LuziApi\Pilotage\Domain\Shared\Money;

final class AnnualReceiptCalculator
{
    /** @param list<ReceiptEntry> $entries */
    public function calculate(int $year, array $entries): AnnualReceiptSummary
    {
        $collectedCents = 0;
        $refundedCents = 0;
        $monthly = array_fill(1, 12, 0);
        $paymentMethods = [];

        foreach ($entries as $entry) {
            $cents = $entry->amount->cents();
            if ($cents >= 0) {
                $collectedCents += $cents;
            } else {
                $refundedCents += abs($cents);
            }

            $monthly[(int) $entry->occurredAt->format('n')] += $cents;
            $method = '' !== $entry->paymentMethod ? $entry->paymentMethod : 'other';
            $paymentMethods[$method] = ($paymentMethods[$method] ?? 0) + $cents;
        }

        usort(
            $entries,
            static fn (ReceiptEntry $left, ReceiptEntry $right): int => $right->occurredAt <=> $left->occurredAt,
        );

        return new AnnualReceiptSummary(
            $year,
            new Money($collectedCents),
            new Money($refundedCents),
            new Money($collectedCents - $refundedCents),
            $monthly,
            $paymentMethods,
            $entries,
        );
    }
}
