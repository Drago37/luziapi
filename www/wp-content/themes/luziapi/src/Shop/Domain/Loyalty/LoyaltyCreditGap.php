<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Loyalty;

/**
 * Commande « Terminée » qui contient des pots admissibles mais n'a **aucune**
 * écriture au journal de fidélité : elle aurait dû créditer et ne l'a pas fait
 * (produit non coché « admissible », ou backfill jamais lancé sur cette commande).
 */
final readonly class LoyaltyCreditGap
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public int $eligiblePots,
    ) {
    }
}
