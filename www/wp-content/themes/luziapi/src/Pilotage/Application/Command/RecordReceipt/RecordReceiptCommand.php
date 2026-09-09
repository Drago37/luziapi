<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordReceipt;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;

final readonly class RecordReceiptCommand
{
    public function __construct(
        public ?int $orderId,
        public DateTimeImmutable $occurredAt,
        public int $amountCents,
        public string $paymentMethod,
        public ReceiptEntryType $type,
        public string $description,
        public int $actorId,
    ) {
    }
}
