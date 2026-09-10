<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\RecordCompletedOrder;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Écrit le crédit de pots d'une commande « Terminée » dans le journal.
 *
 * Idempotent : la clé `credit:{orderId}` empêche tout double crédit si la
 * commande repasse « Terminée » ou si le hook se déclenche deux fois. Ne crédite
 * pas les avantages (`rights_delta` = 0) : ils se déduisent du total net de pots
 * (voir `LoyaltyProgress`).
 */
final readonly class RecordCompletedOrderHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function handle(RecordCompletedOrderCommand $command): ?int
    {
        if ($command->orderId <= 0 || '' === $command->customerKey || $command->pots <= 0) {
            return null;
        }

        $idempotencyKey = 'credit:' . $command->orderId;
        $now = $this->clock->now();

        return $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $command->customerKey,
            type: LoyaltyEntryType::PurchaseCredited,
            potsDelta: $command->pots,
            rightsDelta: 0,
            sourceOrderId: $command->orderId,
            usageOrderId: null,
            reversalOfId: null,
            idempotencyKey: $idempotencyKey,
            reason: sprintf('Commande #%d terminée : %d pot(s) crédité(s)', $command->orderId, $command->pots),
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
