<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain\Gateway;

/**
 * Liens d'identité fidélité : regroupe les clés d'un même client (ses e-mail(s) et
 * téléphone(s)) sous une clé canonique commune, pour que la fidélité s'agrège même
 * quand deux commandes ne partagent aucun champ de contact (2ᵉ e-mail, changement de
 * numéro…). Deux sources : l'auto-alimentation (co-occurrence e-mail+téléphone sur une
 * commande) et la fusion manuelle depuis la fiche client.
 */
interface LoyaltyIdentityLinks
{
    /**
     * Étend un ensemble de clés à toutes celles rattachées au même client (union des
     * groupes touchés). Une clé sans lien se renvoie elle-même. Le résultat contient
     * toujours au moins les clés fournies (dédoublonnées).
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public function expand(array $keys): array;

    /**
     * Rattache toutes ces clés au même client (fusion de leurs groupes respectifs).
     * **Inconditionnel** : réservé à la fusion **manuelle** décidée par un opérateur.
     * Idempotent ; sans effet si moins de deux clés distinctes sont fournies.
     *
     * @param list<string> $keys
     */
    public function union(array $keys): void;

    /**
     * Auto-liaison **prudente** e-mail ↔ téléphone d'une même commande : ne relie que si
     * le téléphone n'est **pas déjà** rattaché à un client. Ainsi plusieurs téléphones
     * s'attachent à un même e-mail (changement de numéro), mais un téléphone déjà pris
     * n'absorbe jamais automatiquement un 2ᵉ e-mail (téléphone de foyer / partagé) — ce
     * cas ambigu relève de la fusion manuelle. Sans effet si une clé manque.
     */
    public function autoLink(string $emailKey, string $phoneKey): void;

    /**
     * Détache ces clés de tout groupe : elles reforment un groupe à part (les autres clés
     * de l'ancien groupe restent regroupées entre elles). Sert de « défusion » pour
     * corriger une fusion manuelle erronée. Sans effet si aucune clé valide.
     *
     * @param list<string> $keys
     */
    public function unlink(array $keys): void;
}
