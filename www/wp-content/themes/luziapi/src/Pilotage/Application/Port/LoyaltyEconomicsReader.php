<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

interface LoyaltyEconomicsReader
{
    /**
     * Agrège, sur un ensemble de commandes **terminées**, les pots achetés
     * (produits admissibles), les pots offerts (geste + fidélité) et la remise
     * remerciement totale (centimes). Les commandes non terminées sont ignorées :
     * les pots ne sont acquis qu'à « Terminée ».
     *
     * @param list<int> $orderIds
     *
     * @return array{potsBought: int, offeredPots: int, discountCents: int}
     */
    public function forOrderIds(array $orderIds): array;
}
