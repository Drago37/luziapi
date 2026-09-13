<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Domain;

use DateTimeImmutable;

/**
 * Un contact abonné (source de vérité : Brevo). Tout abonné a un e-mail ; il est
 * abonné SMS s'il porte un numéro de mobile consenti.
 */
final readonly class Subscriber
{
    public function __construct(
        public string $email,
        public bool $smsSubscribed,
        public ?string $phone = null,
        public ?DateTimeImmutable $subscribedAt = null,
    ) {
    }
}
