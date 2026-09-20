<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Port;

use LuziApi\Shop\Application\Command\ApplyThankYouDiscount\AppliedThankYouDiscount;
use LuziApi\Shop\Domain\Sales\ThankYouDiscount;

interface OrderDiscountWriter
{
    /**
     * Applique une vraie remise (réduction, pas des frais négatifs) sur une
     * commande existante et renvoie le montant réellement remisé.
     */
    public function apply(int $orderId, ThankYouDiscount $discount): AppliedThankYouDiscount;
}
