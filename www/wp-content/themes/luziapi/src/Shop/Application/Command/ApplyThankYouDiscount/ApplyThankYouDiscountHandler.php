<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\ApplyThankYouDiscount;

use InvalidArgumentException;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Shop\Domain\Activity\ActivityCategory;
use LuziApi\Shop\Domain\Gateway\OrderDiscountWriter;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use LuziApi\Shop\Domain\Receipt\ReceiptRepository;

/**
 * Applique une remise remerciement sur une commande existante (a posteriori).
 *
 * - la remise est portée par WooCommerce comme une vraie réduction (adaptateur) ;
 * - si la commande était déjà encaissée (une recette existe au registre), on
 *   corrige la recette d'un `Refund` du montant remisé, pour que la compta reste
 *   juste ;
 * - le geste est tracé dans le journal d'activité.
 */
final readonly class ApplyThankYouDiscountHandler
{
    public function __construct(
        private OrderDiscountWriter $orders,
        private RecordReceiptHandler $recordReceipt,
        private ReceiptRepository $receipts,
        private ActivityRecorder $activity,
        private Clock $clock,
    ) {
    }

    public function handle(ApplyThankYouDiscountCommand $command): AppliedThankYouDiscount
    {
        if ($command->orderId <= 0) {
            throw new InvalidArgumentException('A valid order is required.');
        }

        $applied = $this->orders->apply($command->orderId, $command->discount);
        if ($applied->discountCents <= 0) {
            return $applied;
        }

        // Corrige la recette seulement si la commande avait déjà été encaissée.
        $collected = $this->receipts->netTotalsByOrderIds([$command->orderId])[$command->orderId] ?? 0;
        if ($collected > 0) {
            $this->recordReceipt->handle(new RecordReceiptCommand(
                $command->orderId,
                $this->clock->now(),
                $applied->discountCents,
                $applied->paymentMethod,
                ReceiptEntryType::Refund,
                sprintf('Remise remerciement — commande n°%s', $applied->orderNumber),
                $command->actorId,
            ));
        }

        $this->activity->record(
            ActivityCategory::Receipt,
            'thankyou_discount_applied',
            'order',
            $command->orderId,
            sprintf('Remise remerciement de %s sur la commande n°%s', $this->euros($applied->discountCents), $applied->orderNumber),
            ['discount_cents' => (string) $applied->discountCents, 'receipt_corrected' => $collected > 0 ? 'yes' : 'no'],
            $command->actorId,
        );

        return $applied;
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
