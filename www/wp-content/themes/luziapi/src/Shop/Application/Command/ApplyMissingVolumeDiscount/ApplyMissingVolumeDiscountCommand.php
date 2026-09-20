<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount;

/**
 * Rattrape la remise de volume manquante sur une commande passée (ex. Vente créée
 * avant la correction du bug) et corrige la recette au registre si déjà encaissée.
 */
final readonly class ApplyMissingVolumeDiscountCommand
{
    public function __construct(
        public int $orderId,
        public int $actorId,
    ) {
    }
}
