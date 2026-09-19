<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

use LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount\AppliedVolumeDiscount;

interface OrderVolumeDiscountWriter
{
    /**
     * Ajoute à une commande existante la part MANQUANTE de la remise de volume
     * (−1 €/pot dès 2 pots payés), en fee négatif, et renvoie le montant ajouté.
     * Idempotent : renvoie 0 si la remise correcte est déjà présente.
     */
    public function applyMissing(int $orderId): AppliedVolumeDiscount;
}
