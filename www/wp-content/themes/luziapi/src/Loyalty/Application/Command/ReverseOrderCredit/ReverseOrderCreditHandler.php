<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReverseOrderCredit;

use LuziApi\Loyalty\Application\Port\Clock;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Contre-passe le crédit d'une commande. Idempotent et sûr :
 *
 * - ne fait rien si la commande n'a jamais été créditée (`credit:{orderId}`
 *   absent) — rien à annuler ;
 * - ne fait rien si la contre-passation existe déjà (`reverse:{orderId}`) ;
 * - sinon écrit une contre-passation exactement opposée au crédit d'origine
 *   (`reversalOfId` la relie), pour que le total net revienne à zéro quelle que
 *   soit l'évolution ultérieure de la commande.
 */
final readonly class ReverseOrderCreditHandler
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function handle(ReverseOrderCreditCommand $command): ?int
    {
        if ($command->orderId <= 0) {
            return null;
        }

        $reverseKey = 'reverse:' . $command->orderId;
        if ($this->ledger->hasEntryForIdempotencyKey($reverseKey)) {
            return null;
        }

        $credit = $this->ledger->findByIdempotencyKey('credit:' . $command->orderId);
        if (null === $credit || 0 === $credit->potsDelta) {
            return null;
        }

        $now = $this->clock->now();
        $reason = '' !== $command->reason
            ? $command->reason
            : sprintf('Commande #%d : crédit contre-passé', $command->orderId);

        return $this->ledger->append(new NewLoyaltyEntry(
            customerKey: $credit->customerKey,
            type: LoyaltyEntryType::PurchaseReversed,
            potsDelta: -$credit->potsDelta,
            rightsDelta: -$credit->rightsDelta,
            sourceOrderId: $command->orderId,
            usageOrderId: null,
            reversalOfId: $credit->id,
            idempotencyKey: $reverseKey,
            reason: $reason,
            createdBy: $command->createdBy,
            occurredAt: $now,
            createdAt: $now,
        ));
    }
}
