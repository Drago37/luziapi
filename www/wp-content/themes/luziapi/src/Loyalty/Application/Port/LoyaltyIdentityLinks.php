<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Port;

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
     * Idempotent ; sans effet si moins de deux clés distinctes sont fournies.
     *
     * @param list<string> $keys
     */
    public function union(array $keys): void;
}
