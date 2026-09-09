<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Inventory;

enum StockMovementType: string
{
    case Harvest = 'harvest';
    case Broken = 'broken';
    case Tasting = 'tasting';
    case Gift = 'gift';
    case PersonalUse = 'personal_use';
    case Correction = 'correction';
    case OrderSale = 'order_sale';
    case OrderRestoration = 'order_restoration';

    public function label(): string
    {
        return match ($this) {
            self::Harvest => 'Nouvelle récolte mise en pots',
            self::Broken => 'Pot cassé',
            self::Tasting => 'Dégustation',
            self::Gift => 'Cadeau',
            self::PersonalUse => 'Consommation personnelle',
            self::Correction => 'Correction d’inventaire',
            self::OrderSale => 'Vente — commande',
            self::OrderRestoration => 'Restauration — commande',
        };
    }

    public function isManual(): bool
    {
        return in_array($this, [self::Broken, self::Tasting, self::Gift, self::PersonalUse, self::Correction], true);
    }
}
