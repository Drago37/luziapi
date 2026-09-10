<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Application\Port\IdGenerator;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Écrit, si nécessaire, les écritures qui amènent le journal d'une commande à son
 * état cible : pots crédités = `targetPots`, avantages consommés = `targetRewards`.
 * Convergent et idempotent par construction : si le journal est déjà à la cible,
 * aucun écart n'est écrit ; sinon on écrit exactement le delta.
 */
final readonly class ReconcileOrderLoyaltyHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    public function handle(ReconcileOrderLoyaltyCommand $command): void
    {
        if ($command->orderId <= 0 || '' === $command->customerKey) {
            return;
        }

        $current = $this->ledger->orderTotals($command->orderId);
        $now = $this->clock->now();

        $deltaPots = $command->targetPots - $current['pots'];
        if (0 !== $deltaPots) {
            $this->append(
                $command,
                $now,
                $deltaPots > 0 ? LoyaltyEntryType::PurchaseCredited : LoyaltyEntryType::PurchaseReversed,
                potsDelta: $deltaPots,
                rightsDelta: 0,
                keyPrefix: 'reconcile-pots',
                reason: sprintf('Commande #%d : pots ajustés à %d (%+d)', $command->orderId, $command->targetPots, $deltaPots),
            );
        }

        // Une consommation d'avantage est un delta NÉGATIF de droits.
        $targetRights = -$command->targetRewards;
        $deltaRights = $targetRights - $current['rights'];
        if (0 !== $deltaRights) {
            $this->append(
                $command,
                $now,
                $deltaRights < 0 ? LoyaltyEntryType::RewardConsumed : LoyaltyEntryType::RewardRestored,
                potsDelta: 0,
                rightsDelta: $deltaRights,
                keyPrefix: 'reconcile-rights',
                reason: sprintf('Commande #%d : avantages utilisés ajustés à %d', $command->orderId, $command->targetRewards),
            );
        }
    }

    private function append(
        ReconcileOrderLoyaltyCommand $command,
        \DateTimeImmutable $now,
        LoyaltyEntryType $type,
        int $potsDelta,
        int $rightsDelta,
        string $keyPrefix,
        string $reason,
    ): void {
        $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $command->customerKey,
            type: $type,
            potsDelta: $potsDelta,
            rightsDelta: $rightsDelta,
            sourceOrderId: $command->orderId,
            usageOrderId: 0 !== $rightsDelta ? $command->orderId : null,
            reversalOfId: null,
            idempotencyKey: $keyPrefix . ':' . $command->orderId . ':' . $this->ids->newId(),
            reason: $reason,
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
