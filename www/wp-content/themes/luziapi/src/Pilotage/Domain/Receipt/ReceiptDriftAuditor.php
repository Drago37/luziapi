<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Receipt;

/**
 * Trie les écarts commande↔recette produits par {@see ReceiptReconciliationProjector}
 * et les recettes orphelines en un {@see ReceiptDriftReport}. Service pur : aucune I/O.
 */
final class ReceiptDriftAuditor
{
    /**
     * @param list<ReceiptReconciliation> $reconciliations écarts commande↔recette
     * @param list<OrphanReceipt>         $orphanReceipts  recettes nettes d'une commande disparue
     */
    public function audit(array $reconciliations, array $orphanReceipts): ReceiptDriftReport
    {
        $missing = [];
        $divergent = [];

        foreach ($reconciliations as $reconciliation) {
            if (0 === $reconciliation->recorded->cents()) {
                $missing[] = $reconciliation;
            } else {
                $divergent[] = $reconciliation;
            }
        }

        return new ReceiptDriftReport($missing, $divergent, $orphanReceipts);
    }
}
