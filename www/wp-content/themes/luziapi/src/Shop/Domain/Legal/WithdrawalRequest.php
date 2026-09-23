<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Legal;

/**
 * Demande de rétractation saisie par un client : validation des champs et
 * libellé de la portée. L'identification de la commande et l'envoi des e-mails
 * restent à la charge de l'hôte WordPress/WooCommerce.
 */
final readonly class WithdrawalRequest
{
    public function __construct(
        private string $orderNumber,
        private string $email,
        private string $scope,
        private string $details,
    ) {
    }

    /**
     * Erreurs de saisie, indépendantes de l'identification de la commande.
     *
     * @param bool $emailIsValid résultat de la validation e-mail confiée à l'hôte
     *
     * @return list<string>
     */
    public function fieldErrors(bool $emailIsValid): array
    {
        $errors = [];

        if ('' === $this->orderNumber || '' === $this->email) {
            $errors[] = 'Renseignez le numéro de commande et l’adresse e-mail utilisée lors de l’achat.';
        }

        if ('' !== $this->email && ! $emailIsValid) {
            $errors[] = 'L’adresse e-mail renseignée n’est pas valide.';
        }

        if ('part' === $this->scope && '' === $this->details) {
            $errors[] = 'Précisez les produits concernés par votre demande.';
        }

        return $errors;
    }

    public function scopeLabel(): string
    {
        return 'part' === $this->scope
            ? 'Une partie de la commande : ' . $this->details
            : 'La totalité de la commande';
    }
}
