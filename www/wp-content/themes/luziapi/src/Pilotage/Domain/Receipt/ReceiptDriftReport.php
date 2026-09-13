<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

/**
 * Bilan de la dérive entre les recettes enregistrées et les commandes, sur une période.
 * Trois familles d'anomalie : commande commercialement valide sans recette, commande
 * dont le montant enregistré diffère de l'attendu, et recette orpheline (commande disparue).
 */
final readonly class ReceiptDriftReport
{
    /**
     * @param list<ReceiptReconciliation> $missingReceipts   commandes valides sans recette
     * @param list<ReceiptReconciliation> $divergentReceipts commandes au montant enregistré divergent
     * @param list<OrphanReceipt>         $orphanReceipts     recettes rattachées à une commande disparue
     */
    public function __construct(
        public array $missingReceipts,
        public array $divergentReceipts,
        public array $orphanReceipts,
    ) {
    }

    public function hasDrift(): bool
    {
        return [] !== $this->missingReceipts
            || [] !== $this->divergentReceipts
            || [] !== $this->orphanReceipts;
    }

    public function anomalyCount(): int
    {
        return count($this->missingReceipts)
            + count($this->divergentReceipts)
            + count($this->orphanReceipts);
    }

    /**
     * Somme, en centimes, des écarts en valeur absolue : donne l'ampleur monétaire
     * totale de la dérive, pour un seuil d'alerte.
     */
    public function totalDriftCents(): int
    {
        $total = 0;

        foreach ($this->missingReceipts as $reconciliation) {
            $total += abs($reconciliation->difference->cents());
        }

        foreach ($this->divergentReceipts as $reconciliation) {
            $total += abs($reconciliation->difference->cents());
        }

        foreach ($this->orphanReceipts as $orphan) {
            $total += abs($orphan->net->cents());
        }

        return $total;
    }
}
