<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount;

use InvalidArgumentException;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Shop\Application\Port\Clock;
use LuziApi\Shop\Application\Port\OrderVolumeDiscountWriter;
use LuziApi\Shop\Domain\Activity\ActivityCategory;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use LuziApi\Shop\Domain\Receipt\ReceiptRepository;

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

        // Idempotent : pose la part de remise manquante (rien si déjà correcte).
        $applied = $this->orders->applyMissing($command->orderId);

        // Réconcilie le registre sur le total CORRIGÉ de la commande plutôt que de
        // contre-passer un montant fixe. Deux bénéfices décisifs :
        //  - auto-réparant : si un rattrapage précédent a posé le fee mais échoué
        //    avant d'écrire la recette, rejouer termine la correction (même si la
        //    remise n'a plus rien à appliquer, `appliedCents` valant alors 0) ;
        //  - jamais de sur-remboursement : une recette déjà juste (encaissé == total)
        //    donne un delta nul, donc aucune contre-passe.
        // On ne corrige que si la commande a été encaissée (une recette existe).
        $collected = $this->receipts->netTotalsByOrderIds([$command->orderId])[$command->orderId] ?? 0;
        $overCollected = $collected > 0 ? max(0, $collected - $applied->orderTotalCents) : 0;

        if ($applied->appliedCents <= 0 && $overCollected <= 0) {
            return $applied; // remise correcte ET registre déjà aligné : rien à faire
        }

        if ($overCollected > 0) {
            $this->recordReceipt->handle(new RecordReceiptCommand(
                $command->orderId,
                $this->clock->now(),
                $overCollected,
                $applied->paymentMethod,
                ReceiptEntryType::Refund,
                sprintf('Rattrapage remise de volume — commande n°%s', $applied->orderNumber),
                $command->actorId,
            ));
        }

        $reportedCents = $applied->appliedCents > 0 ? $applied->appliedCents : $overCollected;
        $this->activity->record(
            ActivityCategory::Receipt,
            'volume_discount_backfilled',
            'order',
            $command->orderId,
            sprintf('Rattrapage remise de volume de %s sur la commande n°%s', $this->euros($reportedCents), $applied->orderNumber),
            ['applied_cents' => (string) $applied->appliedCents, 'receipt_corrected_cents' => (string) $overCollected],
            $command->actorId,
        );

        return $applied;
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
