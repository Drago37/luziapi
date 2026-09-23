<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Customer;

use DateTimeImmutable;

/**
 * Stocke la fiche client dédiée — un enregistrement éditable, indexé par identité
 * (même schéma que la catégorie), qui surcharge l'affichage issu des commandes SANS
 * réécrire ces dernières (l'historique et les factures restent intacts).
 */
interface CustomerProfileRepository
{
    /**
     * Fiches dédiées connues pour les identités données.
     *
     * @param list<string> $customerIds
     *
     * @return array<string, CustomerBilling> indexé par identité
     */
    public function forCustomerIds(array $customerIds): array;

    /**
     * Enregistre la fiche sur toutes les identités du client, de sorte qu'elle soit
     * retrouvée quelle que soit l'identité sur laquelle la projection retombe.
     *
     * @param list<string> $customerIds
     */
    public function save(
        array $customerIds,
        CustomerBilling $billing,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void;
}
