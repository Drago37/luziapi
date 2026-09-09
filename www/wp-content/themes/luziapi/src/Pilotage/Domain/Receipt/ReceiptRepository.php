<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

use DateTimeImmutable;

interface ReceiptRepository
{
    public function add(NewReceiptEntry $entry): ReceiptEntry;

    public function find(int $id): ?ReceiptEntry;

    public function hasReversalFor(int $entryId): bool;

    /** @return list<ReceiptEntry> */
    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, int> Total net encaissé en centimes, indexé par commande.
     */
    public function netTotalsByOrderIds(array $orderIds): array;
}
