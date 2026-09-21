<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Domain\Gateway;

use LuziApi\Newsletter\Domain\Subscriber;
use LuziApi\Newsletter\Domain\SubscriptionStatus;

/**
 * Accès en LECTURE SEULE aux abonnés (implémenté par Brevo). Le pilotage affiche,
 * il ne modifie jamais les abonnements : la gestion se fait dans Brevo.
 */
interface SubscriberDirectory
{
    /**
     * Le répertoire est-il utilisable (clé API présente) ? Sert à afficher un état
     * « indisponible » plutôt que des données vides trompeuses.
     */
    public function isConfigured(): bool;

    /**
     * Tous les abonnés de la liste, e-mail d'abord.
     *
     * @return list<Subscriber>
     */
    public function all(): array;

    /**
     * État d'abonnement d'un client identifié par e-mail et/ou téléphone.
     * `null` si le répertoire est indisponible ou le contact introuvable.
     */
    public function statusFor(?string $email, ?string $phone): ?SubscriptionStatus;
}
