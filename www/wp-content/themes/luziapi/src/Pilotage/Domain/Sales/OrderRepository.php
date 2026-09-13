<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use DateTimeImmutable;

interface OrderRepository
{
    /**
     * @return list<OrderSnapshot>
     */
    public function createdBetween(DateTimeImmutable $start, DateTimeImmutable $end): array;

    public function firstOrderDate(): ?DateTimeImmutable;

    /**
     * Parmi les identifiants fournis, ceux qui correspondent encore à une commande
     * existante (les commandes à la corbeille ou supprimées en sont exclues). Sert à
     * repérer les recettes orphelines : une recette pointant un identifiant absent
     * de ce retour se rattache à une commande disparue.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function existingOrderIds(array $ids): array;
}
