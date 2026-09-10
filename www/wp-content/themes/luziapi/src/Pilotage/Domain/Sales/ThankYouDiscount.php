<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use InvalidArgumentException;

/**
 * Remise « remerciement » : geste commercial monétaire libre (non lié aux
 * avantages fidélité), en pourcentage (1..100) ou en montant fixe (centimes).
 *
 * La remise réellement appliquée est toujours bornée au total remisable :
 * `computeCents()` ne rend jamais plus que la base ni moins que zéro.
 */
final readonly class ThankYouDiscount
{
    private function __construct(
        public ThankYouDiscountType $type,
        public int $value,
    ) {
    }

    public static function percent(int $percent): self
    {
        if ($percent < 1 || $percent > 100) {
            throw new InvalidArgumentException('A percentage discount must be between 1 and 100.');
        }

        return new self(ThankYouDiscountType::Percent, $percent);
    }

    public static function amount(int $cents): self
    {
        if ($cents < 1) {
            throw new InvalidArgumentException('An amount discount must be positive.');
        }

        return new self(ThankYouDiscountType::Amount, $cents);
    }

    /**
     * Construit une remise à partir d'une saisie brute, ou `null` si aucune remise
     * n'est demandée (type vide ou valeur nulle).
     */
    public static function fromInput(string $type, int $value): ?self
    {
        if ('' === $type || $value <= 0) {
            return null;
        }

        return match ($type) {
            ThankYouDiscountType::Percent->value => self::percent($value),
            ThankYouDiscountType::Amount->value => self::amount($value),
            default => throw new InvalidArgumentException('Unknown thank-you discount type.'),
        };
    }

    /**
     * Remise en centimes à appliquer sur une base (total remisable), bornée à
     * `[0, base]`.
     */
    public function computeCents(int $baseCents): int
    {
        if ($baseCents <= 0) {
            return 0;
        }

        $discount = match ($this->type) {
            ThankYouDiscountType::Percent => intdiv($baseCents * $this->value, 100),
            ThankYouDiscountType::Amount => $this->value,
        };

        return max(0, min($baseCents, $discount));
    }
}
