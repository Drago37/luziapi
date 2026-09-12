<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

use InvalidArgumentException;

/**
 * Répartit, par produit, une quantité totale et « dont offert » en lignes payées
 * et lignes offertes.
 *
 * Sémantique « dont » : l'offert fait **partie** de la quantité (sous-ensemble),
 * il ne s'ajoute pas. « 3 pots dont 1 offert » = 2 payés + 1 offert ; « 1 pot dont
 * 1 offert » = 0 payé + 1 offert (gratuit). On facture donc `total − offert`.
 */
final readonly class QuickSaleLines
{
    /**
     * @param array<int, int> $totals  productId => quantité totale (dont l'offert)
     * @param array<int, int> $offered productId => dont offert
     *
     * @return array{paid: list<QuickSaleLine>, gifts: list<QuickSaleLine>}
     */
    public static function split(array $totals, array $offered): array
    {
        $paid = [];
        $gifts = [];

        foreach ($totals as $productId => $total) {
            $productId = (int) $productId;
            $total = max(0, (int) $total);
            $gift = max(0, (int) ($offered[$productId] ?? 0));
            if ($gift > $total) {
                throw new InvalidArgumentException('Offered quantity cannot exceed the total quantity.');
            }

            $sold = $total - $gift;
            if ($sold > 0) {
                $paid[] = new QuickSaleLine($productId, $sold);
            }
            if ($gift > 0) {
                $gifts[] = new QuickSaleLine($productId, $gift);
            }
        }

        // Un « dont offert » sans quantité totale correspondante n'a pas de sens.
        foreach ($offered as $productId => $gift) {
            if (max(0, (int) $gift) > 0 && ! isset($totals[(int) $productId])) {
                throw new InvalidArgumentException('Offered quantity cannot exceed the total quantity.');
            }
        }

        return ['paid' => $paid, 'gifts' => $gifts];
    }
}
