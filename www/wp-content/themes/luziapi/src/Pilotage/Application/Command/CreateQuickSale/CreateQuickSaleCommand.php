<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

use DateTimeImmutable;

final readonly class CreateQuickSaleCommand
{
    /** @param list<QuickSaleLine> $lines */
    public function __construct(
        public array $lines,
        public string $customerName,
        public string $email,
        public string $phone,
        public string $address,
        public string $postcode,
        public string $city,
        public string $source,
        public string $paymentMethod,
        public string $fulfillment,
        public bool $paid,
        public bool $sendEmail,
        public DateTimeImmutable $occurredAt,
        public int $actorId,
        public string $requestId,
    ) {
    }
}
