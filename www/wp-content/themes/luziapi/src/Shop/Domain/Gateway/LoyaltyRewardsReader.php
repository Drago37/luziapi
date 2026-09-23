<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Gateway;

/**
 * Lecture des avantages de fidélité disponibles (non encore réclamés) par client,
 * pour le tableau de bord de pilotage. Alimenté par le module Loyalty ; le port
 * garde le Pilotage indépendant de son implémentation.
 */
interface LoyaltyRewardsReader
{
    /**
     * Avantages disponibles de plusieurs clients d'un coup. La clé de sortie est
     * l'identifiant de client fourni en entrée.
     *
     * @param array<string, list<string>> $keysByCustomer identifiant client => ses clés d'identité
     *
     * @return array<string, int>
     */
    public function availableRewardsByCustomer(array $keysByCustomer): array;
}
