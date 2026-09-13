<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Domain;

/**
 * État d'abonnement d'un client (e-mail / SMS), tel que connu de Brevo. Sert
 * l'encart lecture seule de la fiche client — le pilotage n'écrit jamais dans Brevo.
 */
final readonly class SubscriptionStatus
{
    public function __construct(
        public bool $emailSubscribed,
        public bool $smsSubscribed,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false);
    }

    public function isSubscribed(): bool
    {
        return $this->emailSubscribed || $this->smsSubscribed;
    }
}
