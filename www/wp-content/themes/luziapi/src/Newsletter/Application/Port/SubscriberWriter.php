<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Port;

use LuziApi\Newsletter\Domain\SubscriptionStatus;

/**
 * Écriture des abonnements (implémentée par Brevo). Le contact est identifié par son
 * e-mail ; on applique l'état souhaité par canal (inscrire / désinscrire e-mail et SMS).
 *
 * Inscription DIRECTE (pas de double opt-in) : cocher inscrit immédiatement, décocher
 * désinscrit (blacklist du canal). Le consentement est réputé recueilli par l'exploitant.
 */
interface SubscriberWriter
{
    public function isConfigured(): bool;

    /**
     * Applique l'état d'abonnement souhaité sur le contact identifié par `$email`, puis
     * RELIT le contact pour confirmer l'état réellement enregistré côté Brevo (le retour
     * n'est pas une supposition : c'est ce que Brevo renvoie après écriture).
     * `$phone` (format international, ou vide) porte le numéro pour le canal SMS.
     *
     * @throws \RuntimeException si l'écriture échoue, si la relecture est impossible, ou
     *                          si l'état confirmé ne correspond pas à l'état demandé
     */
    public function setSubscription(string $email, string $phone, bool $emailSubscribed, bool $smsSubscribed): SubscriptionStatus;
}
