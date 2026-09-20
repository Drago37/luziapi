<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\RecordReceipt;

use DateTimeImmutable;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;

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
