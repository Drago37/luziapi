<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

interface LoyaltyEconomicsReader
{
    /**
     * Agrège, sur un ensemble de commandes, la remise remerciement totale (en
     * centimes) et le nombre de pots offerts (geste + fidélité). Les commandes
     * annulées / remboursées / échouées sont ignorées (l'avantage n'a pas été
     * réellement reçu).
     *
     * @param list<int> $orderIds
     *
     * @return array{discountCents: int, offeredPots: int}
     */
    public function forOrderIds(array $orderIds): array;
}
