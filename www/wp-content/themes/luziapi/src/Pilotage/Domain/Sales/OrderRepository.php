<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Sales;

use DateTimeImmutable;

interface OrderRepository
{
    /**
     * @return list<OrderSnapshot>
     */
    public function createdBetween(DateTimeImmutable $start, DateTimeImmutable $end): array;

    public function firstOrderDate(): ?DateTimeImmutable;
}
