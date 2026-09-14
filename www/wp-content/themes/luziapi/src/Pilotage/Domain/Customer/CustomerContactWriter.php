<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

/**
 * Écrit les coordonnées d'un client. Comme le répertoire est dérivé des commandes,
 * « modifier un client » revient à mettre à jour les champs de facturation de SES
 * commandes — ce qui réaligne aussi son identité (et fusionne d'éventuels doublons).
 */
interface CustomerContactWriter
{
    /**
     * Met à jour les coordonnées sur les commandes données (seuls les champs non
     * vides sont écrits). Renvoie le nombre de commandes modifiées.
     *
     * @param list<int> $orderIds
     */
    public function update(
        array $orderIds,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $city,
    ): int;
}
