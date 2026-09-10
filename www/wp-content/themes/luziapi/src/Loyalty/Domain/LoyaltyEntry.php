<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

use DateTimeImmutable;

/**
 * Écriture stockée du journal de fidélité (lecture seule). Le journal est
 * append-only : une erreur se corrige par une écriture compensatrice, jamais par
 * modification.
 */
final readonly class LoyaltyEntry
{
    public function __construct(
        public int $id,
        public string $customerKey,
        public LoyaltyEntryType $type,
        public int $potsDelta,
        public int $rightsDelta,
        public ?int $sourceOrderId,
        public ?int $usageOrderId,
        public ?int $reversalOfId,
        public string $idempotencyKey,
        public string $reason,
        public int $createdBy,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
