<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetReceiptRegister;

final readonly class GetReceiptRegisterQuery
{
    public function __construct(public ?int $year = null)
    {
    }
}
