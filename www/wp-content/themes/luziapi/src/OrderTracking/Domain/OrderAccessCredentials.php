<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain;

use InvalidArgumentException;

final readonly class OrderAccessCredentials
{
    public string $orderNumber;

    public string $email;

    public function __construct(string $orderNumber, string $email)
    {
        $orderNumber = ltrim(trim($orderNumber), '#');
        $email = mb_strtolower(trim($email));

        if (1 !== preg_match('/^[0-9]{1,20}$/', $orderNumber)) {
            throw new InvalidArgumentException('Invalid order number.');
        }
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid customer email.');
        }

        $this->orderNumber = $orderNumber;
        $this->email = $email;
    }
}
