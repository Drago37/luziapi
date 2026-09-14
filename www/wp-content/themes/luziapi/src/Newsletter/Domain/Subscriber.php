<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Domain;

use DateTimeImmutable;

/**
 * Un contact de la liste (source de vérité : Brevo). Il peut porter un e-mail et/ou un
 * numéro SMS, chacun pouvant être **bloqué** (blacklisté = désinscrit / « STOP »).
 */
final readonly class Subscriber
{
    public function __construct(
        public string $email,
        public ?string $phone = null,
        public bool $emailBlacklisted = false,
        public bool $smsBlacklisted = false,
        public ?DateTimeImmutable $subscribedAt = null,
    ) {
    }

    public function hasEmail(): bool
    {
        return '' !== $this->email;
    }

    public function hasSms(): bool
    {
        return null !== $this->phone && '' !== $this->phone;
    }

    /** Abonné e-mail actif (a un e-mail et ne l'a pas bloqué). */
    public function emailSubscribed(): bool
    {
        return $this->hasEmail() && ! $this->emailBlacklisted;
    }

    /** Abonné SMS actif (a un numéro et ne l'a pas bloqué). */
    public function smsSubscribed(): bool
    {
        return $this->hasSms() && ! $this->smsBlacklisted;
    }

    /** A bloqué au moins un canal qu'il possède. */
    public function hasBlockedChannel(): bool
    {
        return ($this->hasEmail() && $this->emailBlacklisted)
            || ($this->hasSms() && $this->smsBlacklisted);
    }
}
