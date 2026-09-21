<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Notification;

/**
 * Contenu métier d'un e-mail client selon l'étape de la commande : lignes du
 * message, libellé d'étape et ligne de clôture.
 *
 * Reçoit les valeurs de contexte déjà résolues (numéro de commande, échéance
 * formatée, motif d'annulation, adresse de retrait) : aucune dépendance à
 * WooCommerce ni à WordPress.
 */
final readonly class OrderStatusEmailContent
{
    public function __construct(
        private string $status,
        private string $orderNumber,
        private string $paymentDueLabel = '',
        private int $paymentDeadlineDays = 10,
        private string $cancellationReason = '',
        private string $pickupAddress = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function messageLines(): array
    {
        switch ($this->status) {
            case 'on_hold':
                return [
                    sprintf('J’ai bien reçu votre commande n°%s. Elle est actuellement en attente de confirmation du règlement.', $this->orderNumber),
                    '' !== $this->paymentDueLabel
                        ? sprintf('Le règlement par virement bancaire ou WERO doit être reçu au plus tard le %s inclus, soit sous %d jours ouvrés.', $this->paymentDueLabel, $this->paymentDeadlineDays)
                        : sprintf('Le règlement par virement bancaire ou WERO doit être reçu sous %d jours ouvrés.', $this->paymentDeadlineDays),
                    'Sa préparation commencera dès que le paiement aura été confirmé. Sans règlement dans ce délai, la commande sera annulée et les pots remis en stock.',
                ];

            case 'processing':
                return [
                    sprintf('Votre commande n°%s est bien confirmée.', $this->orderNumber),
                    'Je vais maintenant préparer vos pots de miel. Vous recevrez un nouveau message lorsque votre commande sera prête.',
                ];

            case 'out_for_delivery':
                return [
                    sprintf('Votre commande n°%s est prête à être livrée.', $this->orderNumber),
                    'Je prendrai contact avec vous afin de convenir du jour et de l’heure de la livraison à l’adresse indiquée dans votre commande.',
                    'La livraison est proposée uniquement à Bléré et Luzillé.',
                ];

            case 'ready_for_pickup':
                return [
                    sprintf('Votre commande n°%s est prête.', $this->orderNumber),
                    sprintf('Le retrait aura lieu à mon domicile, au %s.', $this->pickupAddress),
                    'Je prendrai contact avec vous afin de convenir du jour et de l’heure du rendez-vous.',
                ];

            case 'completed':
                return [
                    sprintf('Votre commande n°%s a bien été livrée ou retirée.', $this->orderNumber),
                    'Merci pour votre commande et pour votre confiance.',
                    'À bientôt chez LuziApi !',
                ];

            case 'payment_reminder':
                return [
                    sprintf('Je n’ai pas encore reçu le règlement de votre commande n°%s.', $this->orderNumber),
                    '' !== $this->paymentDueLabel
                        ? sprintf('Vous pouvez effectuer le virement bancaire ou le règlement WERO jusqu’au %s inclus.', $this->paymentDueLabel)
                        : 'Vous pouvez encore effectuer le virement bancaire ou le règlement WERO.',
                    'Sans règlement dans le délai prévu, la commande sera automatiquement annulée et les pots seront remis en stock.',
                ];

            case 'cancelled':
                return [
                    sprintf('Votre commande n°%s a été annulée.', $this->orderNumber),
                    'Motif : ' . $this->cancellationReason,
                    'Si un règlement avait déjà été reçu, je prendrai contact avec vous concernant son remboursement.',
                ];
        }

        return [];
    }

    public function label(): string
    {
        return [
            'on_hold'          => 'Règlement en attente',
            'processing'       => 'Commande confirmée',
            'out_for_delivery' => 'En cours de livraison',
            'ready_for_pickup' => 'Prête au retrait',
            'completed'        => 'Commande terminée',
            'payment_reminder' => 'Rappel de règlement',
            'cancelled'        => 'Commande annulée',
        ][$this->status] ?? 'Votre commande';
    }

    public function closingLine(): string
    {
        return [
            'on_hold'          => 'Je reste disponible si vous avez une question sur votre règlement.',
            'processing'       => 'Merci pour votre confiance et pour votre soutien à l’apiculture locale.',
            'out_for_delivery' => 'À très bientôt pour la remise de votre commande.',
            'ready_for_pickup' => 'À très bientôt pour la remise de votre commande.',
            'completed'        => 'Merci pour votre confiance et pour votre soutien à l’apiculture locale.',
            'payment_reminder' => 'Si votre règlement a déjà été effectué, vous pouvez ignorer ce rappel.',
            'cancelled'        => 'Je reste disponible si vous souhaitez un renseignement.',
        ][$this->status] ?? 'Merci pour votre confiance.';
    }
}
