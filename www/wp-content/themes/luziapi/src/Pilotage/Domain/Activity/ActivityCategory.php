<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Activity;

enum ActivityCategory: string
{
    case Order = 'order';
    case Customer = 'customer';
    case Harvest = 'harvest';
    case Stock = 'stock';
    case Receipt = 'receipt';
    case Settings = 'settings';
    case Export = 'export';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Commande',
            self::Customer => 'Client',
            self::Harvest => 'Récolte',
            self::Stock => 'Stock',
            self::Receipt => 'Recette',
            self::Settings => 'Réglage',
            self::Export => 'Export',
            self::Error => 'Erreur',
        };
    }
}
