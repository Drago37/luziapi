<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class ReceiptEntry
{
    public function __construct(
        public int $id,
        public int $sequenceNumber,
        public ?int $orderId,
        public DateTimeImmutable $occurredAt,
        public Money $amount,
        public string $paymentMethod,
        public ReceiptEntryType $type,
        public string $description,
        public ?int $reversalOfId,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
