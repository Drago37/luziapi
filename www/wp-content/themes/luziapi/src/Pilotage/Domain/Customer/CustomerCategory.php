<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

enum CustomerCategory: string
{
    case Unspecified = 'unspecified';
    case Individual = 'individual';
    case Professional = 'professional';
    case Association = 'association';
    case Collectivity = 'collectivity';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Unspecified => 'Non renseigné',
            self::Individual => 'Particulier',
            self::Professional => 'Professionnel',
            self::Association => 'Association',
            self::Collectivity => 'Collectivité',
            self::Other => 'Autre',
        };
    }
}
