<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Shared;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        private int $cents,
        private string $currency = 'EUR',
    ) {
        if ('' === trim($currency)) {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, $currency);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money currencies must match.');
        }
    }
}
