<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

final readonly class NormalizedPhone
{
    private function __construct(private string $digits)
    {
    }

    public static function fromString(string $phone): ?self
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0033')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '330')) {
            $digits = '33' . substr($digits, 3);
        }
        if (10 === strlen($digits) && str_starts_with($digits, '0')) {
            $digits = '33' . substr($digits, 1);
        }

        return strlen($digits) >= 6 ? new self($digits) : null;
    }

    public function value(): string
    {
        return $this->digits;
    }

    public function international(): string
    {
        return '+' . $this->digits;
    }
}
