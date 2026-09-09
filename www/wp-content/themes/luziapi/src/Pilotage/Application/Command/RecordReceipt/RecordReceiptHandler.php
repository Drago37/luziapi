<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordReceipt;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class RecordReceiptHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private Clock $clock,
    ) {
    }

    public function handle(RecordReceiptCommand $command): ReceiptEntry
    {
        if ($command->amountCents <= 0) {
            throw new InvalidArgumentException('Receipt amount must be positive.');
        }
        if (ReceiptEntryType::Reversal === $command->type) {
            throw new InvalidArgumentException('A reversal must be created from an existing entry.');
        }
        if ('' === trim($command->paymentMethod)) {
            throw new InvalidArgumentException('Payment method cannot be empty.');
        }
        if ($command->occurredAt > $this->clock->now()->modify('+5 minutes')) {
            throw new InvalidArgumentException('Receipt date cannot be in the future.');
        }

        $signedCents = ReceiptEntryType::Refund === $command->type
            ? -$command->amountCents
            : $command->amountCents;

        return $this->receipts->add(new NewReceiptEntry(
            $command->orderId,
            $command->occurredAt,
            new Money($signedCents),
            trim($command->paymentMethod),
            $command->type,
            trim($command->description),
            null,
            $command->actorId,
            $this->clock->now(),
        ));
    }
}
