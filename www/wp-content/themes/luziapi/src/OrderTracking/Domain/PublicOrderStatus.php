<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Domain;

final readonly class PublicOrderStatus
{
    /** @return array{label: string, tone: string, progress: int} */
    public static function describe(string $status): array
    {
        return match ($status) {
            'pending' => ['label' => 'En attente de paiement', 'tone' => 'waiting', 'progress' => 1],
            'on-hold' => ['label' => 'Règlement à confirmer', 'tone' => 'waiting', 'progress' => 1],
            'processing' => ['label' => 'En préparation', 'tone' => 'active', 'progress' => 2],
            'out-for-delivery' => ['label' => 'En cours de livraison', 'tone' => 'active', 'progress' => 3],
            'ready-for-pickup' => ['label' => 'Prête au retrait', 'tone' => 'active', 'progress' => 3],
            'completed' => ['label' => 'Terminée', 'tone' => 'complete', 'progress' => 4],
            'cancelled' => ['label' => 'Annulée', 'tone' => 'cancelled', 'progress' => 0],
            'refunded' => ['label' => 'Remboursée', 'tone' => 'cancelled', 'progress' => 0],
            'failed' => ['label' => 'Échouée', 'tone' => 'cancelled', 'progress' => 0],
            default => ['label' => 'En cours de traitement', 'tone' => 'active', 'progress' => 1],
        };
    }

    /** @return list<array{label: string, state: string}> */
    public static function steps(string $status, string $fulfillmentMode): array
    {
        $description = self::describe($status);
        $progress = $description['progress'];
        // Une commande terminée est entièrement remise : sa dernière étape est
        // « faite », pas « en cours ».
        $isComplete = 'complete' === $description['tone'];
        $thirdLabel = 'pickup' === $fulfillmentMode ? 'Prête au retrait' : 'En livraison';

        return array_map(
            static fn (string $label, int $index): array => [
                'label' => $label,
                'state' => 0 === $progress
                    ? 'inactive'
                    : ($index < $progress
                        ? 'done'
                        : ($index === $progress ? ($isComplete ? 'done' : 'current') : 'upcoming')),
            ],
            ['Commande reçue', 'En préparation', $thirdLabel, 'Commande remise'],
            [1, 2, 3, 4],
        );
    }
}
