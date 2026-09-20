<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetReceiptRegister;

final readonly class GetReceiptRegisterQuery
{
    public function __construct(public ?int $year = null)
    {
    }
}
