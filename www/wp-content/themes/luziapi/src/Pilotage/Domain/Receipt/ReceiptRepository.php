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

    /**
     * Supprime toutes les recettes rattachées à une commande. Utilisé quand la
     * commande est mise à la corbeille ou supprimée : sa recette ne doit plus
     * compter au registre (une commande disparue n'est plus un encaissement).
     *
     * @return int Nombre de lignes supprimées.
     */
    public function deleteByOrderId(int $orderId): int;
}
