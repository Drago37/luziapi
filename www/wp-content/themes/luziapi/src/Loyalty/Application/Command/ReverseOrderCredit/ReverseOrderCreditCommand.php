<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Command\ReverseOrderCredit;

/**
 * Contre-passe le crédit de pots d'une commande qui quitte l'état « Terminée »
 * (annulée, remboursée, remise en attente…). Ne porte que l'identifiant de la
 * commande : le montant à contre-passer est relu depuis l'écriture de crédit
 * d'origine, pour un retour exact à zéro.
 */
final readonly class ReverseOrderCreditCommand
{
    public function __construct(
        public int $orderId,
        public string $reason = '',
        public int $createdBy = 0,
    ) {
    }
}
