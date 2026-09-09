<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ReverseReceipt;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class ReverseReceiptHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private Clock $clock,
    ) {
    }

    public function handle(ReverseReceiptCommand $command): ReceiptEntry
    {
        $original = $this->receipts->find($command->entryId);
        if (null === $original) {
            throw new InvalidArgumentException('Receipt entry does not exist.');
        }
        if (ReceiptEntryType::Reversal === $original->type || $this->receipts->hasReversalFor($original->id)) {
            throw new InvalidArgumentException('Receipt entry is already reversed.');
        }
        if ('' === trim($command->reason)) {
            throw new InvalidArgumentException('Reversal reason cannot be empty.');
        }

        return $this->receipts->add(new NewReceiptEntry(
            $original->orderId,
            $this->clock->now(),
            new Money(-$original->amount->cents(), $original->amount->currency()),
            $original->paymentMethod,
            ReceiptEntryType::Reversal,
            trim($command->reason),
            $original->id,
            $command->actorId,
            $this->clock->now(),
        ));
    }
}
