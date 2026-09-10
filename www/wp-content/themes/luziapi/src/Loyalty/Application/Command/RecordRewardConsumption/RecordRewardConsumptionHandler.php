<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\RecordRewardConsumption;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Écrit la consommation d'avantage(s) d'une commande « Terminée » dans le journal.
 *
 * Idempotent : la clé `reward:{orderId}` empêche toute double consommation. Ne
 * touche pas aux pots (`pots_delta` = 0) ; décrémente les avantages
 * (`rights_delta` = -rewards).
 */
final readonly class RecordRewardConsumptionHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function handle(RecordRewardConsumptionCommand $command): ?int
    {
        if ($command->orderId <= 0 || '' === $command->customerKey || $command->rewards <= 0) {
            return null;
        }

        $now = $this->clock->now();

        return $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $command->customerKey,
            type: LoyaltyEntryType::RewardConsumed,
            potsDelta: 0,
            rightsDelta: -$command->rewards,
            sourceOrderId: $command->orderId,
            usageOrderId: $command->orderId,
            reversalOfId: null,
            idempotencyKey: 'reward:' . $command->orderId,
            reason: sprintf('Commande #%d : %d pot(s) offert(s) au titre de la fidélité', $command->orderId, $command->rewards),
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
