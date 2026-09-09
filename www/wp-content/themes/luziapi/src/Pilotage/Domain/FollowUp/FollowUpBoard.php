<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\FollowUp;

final readonly class FollowUpBoard
{
    /**
     * @param list<FollowUpItem> $payments
     * @param list<FollowUpItem> $preparation
     * @param list<FollowUpItem> $handover
     * @param list<FollowUpItem> $inconsistencies
     */
    public function __construct(
        public array $payments,
        public array $preparation,
        public array $handover,
        public array $inconsistencies,
    ) {
    }

    public function totalActions(): int
    {
        return count($this->payments) + count($this->preparation) + count($this->handover) + count($this->inconsistencies);
    }
}
