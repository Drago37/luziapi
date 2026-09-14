<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

/**
 * Coordonnées complètes de facturation d'un client. Sert à la fois de brouillon
 * (préremplissage depuis la dernière commande) et de valeur de la fiche dédiée
 * (l'enregistrement éditable qui surcharge l'affichage sans réécrire les commandes).
 */
final readonly class CustomerBilling
{
    public function __construct(
        public string $firstName = '',
        public string $lastName = '',
        public string $company = '',
        public string $address1 = '',
        public string $address2 = '',
        public string $postcode = '',
        public string $city = '',
        public string $country = '',
        public string $email = '',
        public string $phone = '',
    ) {
    }

    /**
     * Nom d'affichage : « Prénom Nom », en repli sur l'un ou l'autre.
     */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Vrai quand aucun champ n'est renseigné : un enregistrement entièrement vide
     * équivaut à « pas de fiche dédiée » — l'affichage retombe sur la projection.
     */
    public function isEmpty(): bool
    {
        return '' === $this->firstName
            && '' === $this->lastName
            && '' === $this->company
            && '' === $this->address1
            && '' === $this->address2
            && '' === $this->postcode
            && '' === $this->city
            && '' === $this->country
            && '' === $this->email
            && '' === $this->phone;
    }
}
