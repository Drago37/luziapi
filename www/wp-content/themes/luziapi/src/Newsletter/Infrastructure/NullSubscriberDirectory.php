<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Infrastructure;

use LuziApi\Newsletter\Domain\Gateway\SubscriberDirectory;
use LuziApi\Newsletter\Domain\SubscriptionStatus;

/**
 * Répertoire vide, utilisé quand Brevo n'est pas configuré (dev local sans clé API).
 * L'interface affiche alors « abonnés indisponibles » sans planter.
 */
final readonly class NullSubscriberDirectory implements SubscriberDirectory
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function all(): array
    {
        return [];
    }

    public function statusFor(?string $email, ?string $phone): ?SubscriptionStatus
    {
        return null;
    }
}
