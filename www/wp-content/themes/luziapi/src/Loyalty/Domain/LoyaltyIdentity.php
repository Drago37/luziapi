<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Domain;

use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;

/**
 * Clé d'identité client de la fidélité. Elle DOIT être calculée exactement comme
 * `CustomerHistoryProjector::identityId()` du Pilotage, sinon la fiche client
 * afficherait zéro pot : le journal est écrit avec ces clés et relu en agrégeant
 * les `identityIds` du profil Pilotage.
 *
 * Règle de clé (identique au projecteur) : e-mail prioritaire (`email:<email>`
 * en minuscules et sans espaces), sinon téléphone normalisé (`phone:<digits>`).
 * Un test « golden » verrouille la valeur du hash contre toute dérive.
 */
final readonly class LoyaltyIdentity
{
    private function __construct(public string $key)
    {
    }

    /**
     * Clé d'écriture pour une commande : e-mail si présent, sinon téléphone.
     * `null` si le contact n'a ni e-mail ni téléphone exploitable — la commande
     * ne peut alors pas être rattachée à un client (pas de crédit fidélité).
     */
    public static function fromContact(string $email, string $phone): ?self
    {
        $email = strtolower(trim($email));
        if ('' !== $email) {
            return new self(self::hash('email:' . $email));
        }

        $normalizedPhone = NormalizedPhone::fromString($phone)?->value();
        if (null !== $normalizedPhone) {
            return new self(self::hash('phone:' . $normalizedPhone));
        }

        return null;
    }

    /**
     * Toutes les clés d'identité d'un contact (e-mail ET téléphone), pour agréger
     * la fidélité comme le fait la fiche client — un même profil pouvant être connu
     * par son e-mail comme par son téléphone.
     *
     * @return list<string>
     */
    public static function keysForContact(string $email, string $phone): array
    {
        $keys = [];
        $email = strtolower(trim($email));
        if ('' !== $email) {
            $keys[] = self::hash('email:' . $email);
        }
        $normalizedPhone = NormalizedPhone::fromString($phone)?->value();
        if (null !== $normalizedPhone) {
            $keys[] = self::hash('phone:' . $normalizedPhone);
        }

        return $keys;
    }

    /**
     * Hache une clé d'identité brute (`email:…` ou `phone:…`). Exposé pour le
     * golden test et pour rejouer la logique du projecteur si nécessaire.
     */
    public static function hash(string $identityKey): string
    {
        return substr(hash('sha256', $identityKey), 0, 20);
    }
}
