<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Application\Port\IdGenerator;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Écrit un ajustement manuel de pots (`ManualAdjustment`) dans le journal. Chaque
 * ajustement est une action distincte (clé d'idempotence unique) ; ne rien écrire
 * si le delta est nul ou le client absent.
 */
final readonly class AdjustLoyaltyPotsHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    public function handle(AdjustLoyaltyPotsCommand $command): ?int
    {
        if (0 === $command->pots || '' === $command->customerKey) {
            return null;
        }

        $now = $this->clock->now();
        $reason = '' !== trim($command->reason) ? trim($command->reason) : 'Ajustement manuel';

        return $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $command->customerKey,
            type: LoyaltyEntryType::ManualAdjustment,
            potsDelta: $command->pots,
            rightsDelta: 0,
            sourceOrderId: null,
            usageOrderId: null,
            reversalOfId: null,
            idempotencyKey: 'manual:' . $this->ids->newId(),
            reason: $reason,
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
