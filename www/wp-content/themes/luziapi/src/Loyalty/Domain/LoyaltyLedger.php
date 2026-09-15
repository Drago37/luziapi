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

    /**
     * Vrai dès qu'une écriture — quelle que soit sa clé d'idempotence — est rattachée
     * à la commande (comme source). Sert au backfill à ne rétro-créditer que les
     * commandes jamais vues par le moteur, quel que soit le schéma de clés utilisé
     * (crédit direct historique ou réconciliation).
     */
    public function hasEntryForOrder(int $orderId): bool;

    public function findByIdempotencyKey(string $idempotencyKey): ?LoyaltyEntry;

    /**
     * Totaux déjà journalisés pour une commande (toutes écritures dont
     * `source_order_id` vaut `$orderId`) : `pots` = somme des `pots_delta`,
     * `rights` = somme brute des `rights_delta`. Base de la réconciliation.
     *
     * @return array{pots: int, rights: int}
     */
    public function orderTotals(int $orderId): array;

    /**
     * Soldes nets pour un ensemble de clés client (tous les `identityIds` d'un
     * même profil). `pots` = somme des `pots_delta` (les pots n'expirent jamais) ;
     * `rightsConsumed` = solde net des avantages consommés = `max(0, -somme(rights_delta))`
     * (une consommation écrit un delta négatif, sa contre-passation un delta positif :
     * le net revient à zéro).
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
