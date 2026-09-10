<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Port;

interface OrderContactKeys
{
    /**
     * Clés d'identité fidélité (e-mail et téléphone) des clients d'un ensemble de
     * commandes. Sert à afficher la fidélité d'un visiteur du suivi de commande,
     * sans compte, à partir des seules commandes auxquelles sa session donne accès.
     *
     * @param list<int> $orderIds
     *
     * @return list<string>
     */
    public function forOrderIds(array $orderIds): array;
}
