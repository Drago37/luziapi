<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordOrderReceipt;

use DateTimeImmutable;

/**
 * Demande d'enregistrement de l'encaissement d'une commande devenue « Terminée ».
 * Le montant attendu est le total net (total moins remboursements) ; le handler
 * n'enregistre que ce qui manque encore au registre (idempotent).
 */
final readonly class RecordOrderReceiptCommand
{
    public function __construct(
        public int $orderId,
        public int $expectedCents,
        public string $paymentMethod,
        public DateTimeImmutable $occurredAt,
        public int $actorId,
        public string $description,
    ) {
    }
}
