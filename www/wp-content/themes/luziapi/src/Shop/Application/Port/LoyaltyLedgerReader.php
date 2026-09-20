<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Port;

/**
 * Lecture du journal de fidélité pour l'audit de dérive (lecture seule). Alimenté
 * par le module Loyalty ; garde le Pilotage indépendant de son implémentation.
 */
interface LoyaltyLedgerReader
{
    /**
     * Identifiants de commande cités comme source d'au moins une écriture (distincts).
     *
     * @return list<int>
     */
    public function sourceOrderIds(): array;

    /**
     * Solde net de pots journalisé par commande (crédits moins contre-passations).
     *
     * @param list<int> $orderIds
     *
     * @return array<int, int> net de pots par identifiant de commande
     */
    public function netPotsByOrderIds(array $orderIds): array;
}
