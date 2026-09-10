<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

/**
 * Types d'écritures du journal de fidélité (append-only).
 *
 * - `PurchaseCredited` / `PurchaseReversed` : pots gagnés à l'achat et leur
 *   contre-passation (lot 1).
 * - `RewardConsumed` / `RewardRestored` : avantage utilisé (pot offert) et sa
 *   contre-passation si la commande est annulée (lot 2).
 * - `ManualAdjustment` : correction manuelle (réservé).
 *
 * Les avantages *acquis* ne sont pas journalisés : ils se déduisent du total net
 * de pots (voir `LoyaltyProgress`). Seule leur *consommation* l'est.
 */
enum LoyaltyEntryType: string
{
    case PurchaseCredited = 'purchase_credited';
    case PurchaseReversed = 'purchase_reversed';
    case RewardConsumed = 'reward_consumed';
    case RewardRestored = 'reward_restored';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseCredited => 'Pots crédités',
            self::PurchaseReversed => 'Pots contre-passés',
            self::RewardConsumed => 'Avantage utilisé',
            self::RewardRestored => 'Avantage rendu',
            self::ManualAdjustment => 'Correction manuelle',
        };
    }
}
