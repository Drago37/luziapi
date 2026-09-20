<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WooCommerce;

use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountCommand;
use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Branche le rattrapage de la remise de volume déclenché depuis la fiche commande.
 *
 * La case « Corriger la remise de volume » du métabox émet, à l'enregistrement,
 * l'action `luziapi_fix_volume_discount` : ce subscriber appelle alors le handler
 * qui ajoute le fee manquant à la commande et corrige la recette si elle a déjà
 * été encaissée (le registre passe de 52 € affiché à 47 € réellement encaissé).
 * Idempotent : rejouer sur une commande déjà correcte ne fait rien.
 */
final readonly class WooCommerceVolumeDiscountFixSubscriber
{
    public function __construct(
        private ApplyMissingVolumeDiscountHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        add_action('luziapi_fix_volume_discount', [$this, 'onFix'], 10, 2);
    }

    public function onFix(int $orderId, mixed $order = null): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            $applied = $this->handler->handle(
                new ApplyMissingVolumeDiscountCommand($orderId, get_current_user_id()),
            );
            $this->notify(
                $applied->appliedCents > 0
                    ? sprintf('Remise de volume rattrapée : %s appliqués à la commande.', $this->euros($applied->appliedCents))
                    : 'Aucun rattrapage nécessaire : la remise de volume est déjà correcte.',
                false,
            );
        } catch (Throwable $exception) {
            // Ne jamais bloquer l'enregistrement de la commande.
            $this->logger->error('Rattrapage de la remise de volume échoué.', [
                'order_id'  => $orderId,
                'exception' => $exception,
            ]);
            $this->notify('Le rattrapage de la remise de volume a échoué (voir les journaux).', true);
        }
    }

    private function notify(string $message, bool $error): void
    {
        if (! function_exists('set_transient') || ! function_exists('get_current_user_id')) {
            return;
        }
        set_transient(
            'luziapi_volume_fix_notice_' . get_current_user_id(),
            ['message' => $message, 'error' => $error],
            120,
        );
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
