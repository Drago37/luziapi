<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Receipt;

use LuziApi\Shared\Domain\ValueObject\Money;

/**
 * Recette nette rattachée à une commande qui n'existe plus (mise à la corbeille ou
 * supprimée) : elle gonfle l'encaissé au-dessus du chiffre réellement vendu.
 */
final readonly class OrphanReceipt
{
    public function __construct(
        public int $orderId,
        public Money $net,
    ) {
    }
}
