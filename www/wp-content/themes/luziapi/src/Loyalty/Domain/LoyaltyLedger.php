<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

/**
 * Port du journal de fidélité : journal append-only, idempotent par clé.
 * Implémenté côté Infrastructure par un adaptateur WordPress (table dédiée).
 */
interface LoyaltyLedger
{
    /**
     * Ajoute une écriture. L'implémentation DOIT être idempotente sur
     * `idempotencyKey` : si une écriture porte déjà cette clé, ne rien insérer.
     * Retourne l'identifiant de l'écriture créée, ou `null` si un doublon a été
     * ignoré.
     */
    public function append(NewLoyaltyEntry $entry): ?int;

    public function hasEntryForIdempotencyKey(string $idempotencyKey): bool;

    public function findByIdempotencyKey(string $idempotencyKey): ?LoyaltyEntry;

    /**
     * Soldes nets pour un ensemble de clés client (tous les `identityIds` d'un
     * même profil). `pots` = somme des `pots_delta` ; `rightsConsumed` = solde net
     * des avantages consommés = `max(0, -somme(rights_delta))` (une consommation
     * écrit un delta négatif, sa contre-passation un delta positif : le net revient
     * à zéro).
     *
     * @param list<string> $customerKeys
     *
     * @return array{pots: int, rightsConsumed: int, entryCount: int}
     */
    public function totalsForCustomerKeys(array $customerKeys): array;

    /**
     * Soldes bruts par clé client (une entrée par clé ayant au moins un mouvement) :
     * utilisé pour calculer d'un coup les avantages disponibles de plusieurs clients
     * (liste de la Vente). `pots` = somme des `pots_delta`, `rights` = somme brute
     * des `rights_delta`.
     *
     * @param list<string> $customerKeys
     *
     * @return array<string, array{pots: int, rights: int}>
     */
    public function balancesByCustomerKeys(array $customerKeys): array;

    /**
     * Écritures d'un ensemble de clés client, les plus récentes d'abord.
     *
     * @param list<string> $customerKeys
     *
     * @return list<LoyaltyEntry>
     */
    public function entriesForCustomerKeys(array $customerKeys, int $limit = 50): array;
}
