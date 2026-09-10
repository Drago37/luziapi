<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\RecordRewardConsumption;

/**
 * Enregistre la consommation d'avantage(s) fidélité par une commande « Terminée »
 * qui contient des pots offerts au titre de la fidélité. Chaque pot offert
 * consomme un avantage.
 */
final readonly class RecordRewardConsumptionCommand
{
    public function __construct(
        public int $orderId,
        public string $customerKey,
        public int $rewards,
        public int $createdBy = 0,
    ) {
    }
}
