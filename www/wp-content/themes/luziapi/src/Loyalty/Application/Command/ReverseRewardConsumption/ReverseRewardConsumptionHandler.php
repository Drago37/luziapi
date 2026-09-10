<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReverseRewardConsumption;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Rend l'avantage consommé par une commande. Idempotent et sûr :
 *
 * - ne fait rien si la commande n'a jamais consommé d'avantage (`reward:{id}`
 *   absent) ;
 * - ne fait rien si la restitution existe déjà (`reward-reversal:{id}`) ;
 * - sinon écrit une restitution exactement opposée (`rights_delta` positif), pour
 *   que le solde net des avantages revienne à son état d'avant la commande.
 */
final readonly class ReverseRewardConsumptionHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function handle(ReverseRewardConsumptionCommand $command): ?int
    {
        if ($command->orderId <= 0) {
            return null;
        }

        $reverseKey = 'reward-reversal:' . $command->orderId;
        if ($this->ledger->hasEntryForIdempotencyKey($reverseKey)) {
            return null;
        }

        $consumption = $this->ledger->findByIdempotencyKey('reward:' . $command->orderId);
        if (null === $consumption || 0 === $consumption->rightsDelta) {
            return null;
        }

        $now = $this->clock->now();
        $reason = '' !== $command->reason
            ? $command->reason
            : sprintf('Commande #%d : avantage(s) rendu(s)', $command->orderId);

        return $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $consumption->customerKey,
            type: LoyaltyEntryType::RewardRestored,
            potsDelta: 0,
            rightsDelta: -$consumption->rightsDelta,
            sourceOrderId: $command->orderId,
            usageOrderId: $command->orderId,
            reversalOfId: $consumption->id,
            idempotencyKey: $reverseKey,
            reason: $reason,
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
