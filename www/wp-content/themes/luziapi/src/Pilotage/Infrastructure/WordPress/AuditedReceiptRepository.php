<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;

final readonly class AuditedReceiptRepository implements ReceiptRepository
{
    public function __construct(
        private ReceiptRepository $inner,
        private ActivityRecorder $activity,
    ) {
    }

    public function add(NewReceiptEntry $entry): ReceiptEntry
    {
        $stored = $this->inner->add($entry);
        $action = match (true) {
            null !== $stored->reversalOfId => 'receipt_reversed',
            ReceiptEntryType::Refund === $stored->type => 'refund_recorded',
            default => 'receipt_recorded',
        };
        $this->activity->record(
            ActivityCategory::Receipt,
            $action,
            'receipt',
            $stored->id,
            sprintf('%s de %.2f € enregistré', $stored->type->label(), abs($stored->amount->cents()) / 100),
            array_filter([
                'Commande' => null !== $stored->orderId ? (string) $stored->orderId : '',
                'Moyen de règlement' => $stored->paymentMethod,
                'Description' => $stored->description,
            ], static fn (string $value): bool => '' !== $value),
            $stored->createdBy,
        );

        return $stored;
    }

    public function find(int $id): ?ReceiptEntry
    {
        return $this->inner->find($id);
    }

    public function hasReversalFor(int $entryId): bool
    {
        return $this->inner->hasReversalFor($entryId);
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->inner->occurredBetween($start, $end);
    }

    public function netTotalsByOrderIds(array $orderIds): array
    {
        return $this->inner->netTotalsByOrderIds($orderIds);
    }

    public function deleteByOrderId(int $orderId): int
    {
        $removed = $this->inner->deleteByOrderId($orderId);
        if ($removed > 0) {
            $this->activity->record(
                ActivityCategory::Receipt,
                'receipt_deleted',
                'order',
                $orderId,
                sprintf('%d recette(s) retirée(s) : commande #%d supprimée', $removed, $orderId),
                [],
                0,
            );
        }

        return $removed;
    }
}
