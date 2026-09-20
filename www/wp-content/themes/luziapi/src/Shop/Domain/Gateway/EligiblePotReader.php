<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Gateway;

/**
 * Nombre de pots **admissibles** à la fidélité par commande (pots vendus des
 * produits cochés « admissible », offerts et remboursements exclus). Alimenté par
 * WooCommerce ; sert à l'audit à repérer les commandes qui auraient dû créditer.
 */
interface EligiblePotReader
{
    /**
     * @param list<int> $orderIds
     *
     * @return array<int, int> pots admissibles par identifiant de commande (0 si aucun)
     */
    public function eligiblePotsByOrderIds(array $orderIds): array;
}
