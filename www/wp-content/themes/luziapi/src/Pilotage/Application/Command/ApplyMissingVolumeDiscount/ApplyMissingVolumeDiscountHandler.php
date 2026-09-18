<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\OrderVolumeDiscountWriter;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;

/**
 * Rattrape la remise de volume manquante d'une commande passée : ajoute le fee de
 * volume manquant (via l'adaptateur) et, si la commande était déjà encaissée,
 * corrige la recette d'un `Refund` du montant — pour que le registre colle à ce qui
 * a réellement été encaissé (52 € affiché → 47 € réel). Idempotent, tracé au journal.
 */
final readonly class ApplyMissingVolumeDiscountHandler
{
    public function __construct(
        private OrderVolumeDiscountWriter $orders,
        private RecordReceiptHandler $recordReceipt,
        private ReceiptRepository $receipts,
        private ActivityRecorder $activity,
        private Clock $clock,
    ) {
    }

    public function handle(ApplyMissingVolumeDiscountCommand $command): AppliedVolumeDiscount
    {
        if ($command->orderId <= 0) {
            throw new InvalidArgumentException('A valid order is required.');
        }

        $applied = $this->orders->applyMissing($command->orderId);
        if ($applied->appliedCents <= 0) {
            return $applied; // remise déjà correcte : rien à faire
        }

        // Corrige la recette seulement si la commande avait déjà été encaissée.
        $collected = $this->receipts->netTotalsByOrderIds([$command->orderId])[$command->orderId] ?? 0;
        if ($collected > 0) {
            $this->recordReceipt->handle(new RecordReceiptCommand(
                $command->orderId,
                $this->clock->now(),
                $applied->appliedCents,
                $applied->paymentMethod,
                ReceiptEntryType::Refund,
                sprintf('Rattrapage remise de volume — commande n°%s', $applied->orderNumber),
                $command->actorId,
            ));
        }

        $this->activity->record(
            ActivityCategory::Receipt,
            'volume_discount_backfilled',
            'order',
            $command->orderId,
            sprintf('Rattrapage remise de volume de %s sur la commande n°%s', $this->euros($applied->appliedCents), $applied->orderNumber),
            ['applied_cents' => (string) $applied->appliedCents, 'receipt_corrected' => $collected > 0 ? 'yes' : 'no'],
            $command->actorId,
        );

        return $applied;
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
