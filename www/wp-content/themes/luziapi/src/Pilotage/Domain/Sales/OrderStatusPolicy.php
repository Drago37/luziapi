<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

final class OrderStatusPolicy
{
    private const COMMERCIALLY_VALID = [
        'on-hold',
        'processing',
        'out-for-delivery',
        'ready-for-pickup',
        'completed',
        'refunded',
    ];

    public static function isCommerciallyValid(string $status): bool
    {
        return in_array($status, self::COMMERCIALLY_VALID, true);
    }
}
