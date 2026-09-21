<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Sales;

/**
 * Origine d'une commande LuziApi (canal de vente) : liste de référence des
 * canaux et résolution de la source d'une commande.
 */
final class OrderSource
{
    /**
     * Canaux de vente proposés, du plus courant au plus rare.
     *
     * @return array<string, string> valeur => libellé
     */
    public static function options(): array
    {
        return [
            'online'     => 'Boutique en ligne',
            'phone'      => 'Téléphone',
            'market'     => 'Marché / événement',
            'email_form' => 'E-mail / formulaire',
            'social'     => 'Réseaux sociaux',
            'other'      => 'Autre',
        ];
    }

    /**
     * Résout la source d'une commande. Une source explicitement enregistrée
     * prime ; sinon, les anciennes commandes issues du checkout sont reconnues
     * comme « en ligne » sans migration en base.
     */
    public static function resolve(string $storedSource, string $createdVia): string
    {
        $storedSource = trim($storedSource);
        if (isset(self::options()[$storedSource])) {
            return $storedSource;
        }

        return in_array($createdVia, ['checkout', 'store-api'], true) ? 'online' : '';
    }
}
