<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\RecordOrderReceipt;

use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;

/**
 * Enregistre l'encaissement d'une commande « Terminée ». La règle métier LuziApi
 * est que ce statut vaut preuve d'encaissement. L'opération est **idempotente** :
 * on ne porte au registre que le solde manquant (attendu moins déjà encaissé),
 * ce qui évite tout doublon si une recette a déjà été saisie ou rapprochée.
 */
final readonly class RecordOrderReceiptHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private RecordReceiptHandler $recordReceipt,
    ) {
    }

    public function handle(RecordOrderReceiptCommand $command): ?ReceiptEntry
    {
        if ($command->expectedCents <= 0) {
            return null;
        }

        $recorded = $this->receipts->netTotalsByOrderIds([$command->orderId])[$command->orderId] ?? 0;
        $missing = $command->expectedCents - $recorded;
        if ($missing <= 0) {
            return null;
        }

        return $this->recordReceipt->handle(new RecordReceiptCommand(
            $command->orderId,
            $command->occurredAt,
            $missing,
            $command->paymentMethod,
            ReceiptEntryType::Collection,
            $command->description,
            $command->actorId,
        ));
    }
}
