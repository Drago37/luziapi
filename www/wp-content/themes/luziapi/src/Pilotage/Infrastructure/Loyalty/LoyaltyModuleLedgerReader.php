<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\Loyalty;

use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Pilotage\Application\Port\LoyaltyLedgerReader;

/**
 * Adaptateur du port {@see LoyaltyLedgerReader} vers le journal du module Loyalty.
 */
final readonly class LoyaltyModuleLedgerReader implements LoyaltyLedgerReader
{
    public function __construct(
        private LoyaltyLedger $ledger,
    ) {
    }

    public function sourceOrderIds(): array
    {
        return $this->ledger->sourceOrderIds();
    }

    public function netPotsByOrderIds(array $orderIds): array
    {
        $net = [];
        foreach ($orderIds as $orderId) {
            $net[$orderId] = $this->ledger->orderTotals($orderId)['pots'];
        }

        return $net;
    }
}
