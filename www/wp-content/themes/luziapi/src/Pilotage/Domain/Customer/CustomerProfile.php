<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class CustomerProfile
{
    /**
     * @param list<string>        $emails
     * @param list<string>        $phones
     * @param list<string>        $sources
     * @param list<OrderSnapshot> $orders
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $city,
        public array $emails,
        public array $phones,
        public array $sources,
        public array $orders,
        public int $validOrdersCount,
        public Money $orderedTotal,
        public Money $collectedTotal,
        /** @var list<string> */
        public array $favoriteProducts,
        /** @var non-empty-list<string> */
        public array $identityIds,
        public CustomerCategory $category = CustomerCategory::Unspecified,
        public ?CustomerBilling $billingOverride = null,
    ) {
    }

    public function withCategory(CustomerCategory $category): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->city,
            $this->emails,
            $this->phones,
            $this->sources,
            $this->orders,
            $this->validOrdersCount,
            $this->orderedTotal,
            $this->collectedTotal,
            $this->favoriteProducts,
            $this->identityIds,
            $category,
            $this->billingOverride,
        );
    }

    /**
     * Applique la fiche dédiée : l'affichage (nom, ville, e-mail, téléphone) reflète
     * les valeurs saisies, l'identité (donc catégorie et fidélité) reste inchangée.
     * Un champ vide de la fiche retombe sur la valeur issue des commandes.
     */
    public function withOverride(CustomerBilling $billing): self
    {
        $name = '' !== $billing->fullName() ? $billing->fullName() : $this->name;
        $city = '' !== $billing->city ? $billing->city : $this->city;
        $emails = '' !== $billing->email
            ? array_values(array_unique(array_merge([$billing->email], $this->emails)))
            : $this->emails;
        $phones = '' !== $billing->phone
            ? array_values(array_unique(array_merge([$billing->phone], $this->phones)))
            : $this->phones;

        return new self(
            $this->id,
            $name,
            $city,
            $emails,
            $phones,
            $this->sources,
            $this->orders,
            $this->validOrdersCount,
            $this->orderedTotal,
            $this->collectedTotal,
            $this->favoriteProducts,
            $this->identityIds,
            $this->category,
            $billing,
        );
    }

    public function lastOrder(): OrderSnapshot
    {
        return $this->orders[0];
    }

    /**
     * Adresse e-mail à utiliser pour préremplir une nouvelle commande.
     * La première connue fait foi ; vide si le client n'est identifié que par
     * téléphone.
     */
    public function primaryEmail(): string
    {
        return $this->emails[0] ?? '';
    }

    /**
     * Téléphone à utiliser pour préremplir une nouvelle commande.
     */
    public function primaryPhone(): string
    {
        return $this->phones[0] ?? '';
    }
}
