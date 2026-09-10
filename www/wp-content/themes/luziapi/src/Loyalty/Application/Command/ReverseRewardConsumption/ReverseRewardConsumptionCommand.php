<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReverseRewardConsumption;

/**
 * Contre-passe la consommation d'avantage(s) d'une commande qui quitte l'état
 * « Terminée » : l'avantage utilisé est rendu au client. Le montant à rendre est
 * relu depuis l'écriture de consommation d'origine.
 */
final readonly class ReverseRewardConsumptionCommand
{
    public function __construct(
        public int $orderId,
        public string $reason = '',
        public int $createdBy = 0,
    ) {
    }
}
