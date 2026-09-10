<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount;

/**
 * Résultat de l'application d'une remise remerciement sur une commande existante.
 */
final readonly class AppliedThankYouDiscount
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public int $discountCents,
        public string $paymentMethod,
    ) {
    }
}
