<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\FollowUp;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;

final class FollowUpProjector
{
    /**
     * @param list<OrderSnapshot> $orders
     * @param array<int, int>     $receiptTotalsByOrder
     */
    public function project(array $orders, array $receiptTotalsByOrder, DateTimeImmutable $now): FollowUpBoard
    {
        $payments = [];
        $preparation = [];
        $handover = [];
        $inconsistencies = [];

        foreach ($orders as $order) {
            if (in_array($order->status, ['cancelled', 'failed', 'refunded'], true)) {
                continue;
            }
            $age = max(0, (int) $order->createdAt->diff($now)->format('%a'));
            $expectedCents = max(0, $order->total->cents() - $order->refunded->cents());
            $outstandingCents = max(0, $expectedCents - ($receiptTotalsByOrder[$order->id] ?? 0));
            $outstanding = new Money($outstandingCents);

            if ($outstandingCents > 0 && in_array($order->status, ['pending', 'on-hold'], true)) {
                $payments[] = new FollowUpItem($order, 'Règlement à enregistrer', $age, $outstanding);
            }
            if ('processing' === $order->status) {
                $preparation[] = new FollowUpItem($order, 'Commande à préparer', $age, $outstanding);
            }
            if (in_array($order->status, ['out-for-delivery', 'ready-for-pickup'], true)) {
                $handover[] = new FollowUpItem(
                    $order,
                    'out-for-delivery' === $order->status ? 'Livraison à terminer' : 'Retrait à effectuer',
                    $age,
                    $outstanding,
                );
            }
            if ('' === $order->source || ('unknown' === $order->fulfillment && ! in_array($order->status, ['pending'], true))) {
                $reasons = [];
                if ('' === $order->source) {
                    $reasons[] = 'source manquante';
                }
                if ('unknown' === $order->fulfillment && ! in_array($order->status, ['pending'], true)) {
                    $reasons[] = 'mode de remise manquant';
                }
                $inconsistencies[] = new FollowUpItem($order, ucfirst(implode(' et ', $reasons)), $age, $outstanding);
            }
        }

        return new FollowUpBoard($payments, $preparation, $handover, $inconsistencies);
    }
}
