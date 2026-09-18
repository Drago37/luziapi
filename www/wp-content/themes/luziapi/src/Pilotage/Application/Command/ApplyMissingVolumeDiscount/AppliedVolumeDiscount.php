<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount;

/**
 * Résultat du rattrapage de la remise de volume sur une commande existante.
 * `appliedCents` = montant réellement ajouté (0 si la remise était déjà correcte).
 */
final readonly class AppliedVolumeDiscount
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public int $appliedCents,
        public string $paymentMethod,
        public int $paidJars,
    ) {
    }
}
