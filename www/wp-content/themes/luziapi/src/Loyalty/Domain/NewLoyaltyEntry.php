<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

use DateTimeImmutable;

/**
 * Nouvelle écriture à ajouter au journal (sans identifiant). La clé
 * d'idempotence garantit qu'une même opération (ex. crédit d'une commande) n'est
 * pas enregistrée deux fois.
 */
final readonly class NewLoyaltyEntry
{
    public function __construct(
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
