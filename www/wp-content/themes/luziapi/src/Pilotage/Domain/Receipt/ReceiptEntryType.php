<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

enum ReceiptEntryType: string
{
    case Collection = 'collection';
    case Refund = 'refund';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Encaissement',
            self::Refund => 'Remboursement',
            self::Reversal => 'Contre-écriture',
        };
    }
}
