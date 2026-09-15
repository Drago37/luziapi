<?php

/**
 * Cœur partagé de l'audit de dérive du programme de fidélité.
 *
 * Construit le handler à partir des adaptateurs réels (journal NON audité, lecture
 * seule) et renvoie un tableau sérialisable décrivant la dérive. Utilisé par l'outil
 * local (`tools/audit-loyalty-drift.php`, rendu texte) et par le wrapper prod à jeton
 * (`tools/audit-loyalty-drift-prod.php`, rendu JSON).
 *
 * Lecture seule : aucune écriture, aucun e-mail.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;
use LuziApi\Pilotage\Application\Query\AuditLoyaltyDrift\AuditLoyaltyDriftHandler;
use LuziApi\Pilotage\Application\Query\AuditLoyaltyDrift\AuditLoyaltyDriftQuery;
use LuziApi\Pilotage\Domain\Loyalty\LoyaltyCreditGap;
use LuziApi\Pilotage\Domain\Loyalty\OrphanLoyaltyCredit;
use LuziApi\Pilotage\Infrastructure\Loyalty\LoyaltyModuleLedgerReader;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceEligiblePotReader;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;

if (! function_exists('luziapi_audit_loyalty_drift_data')) {
    /**
     * @return array{
     *     year: int|null,
     *     has_drift: bool,
     *     anomaly_count: int,
     *     total_missing_pots: int,
     *     total_orphan_pots: int,
     *     gaps: list<array{order: string, id: int, pots: int}>,
     *     orphans: list<array{order_id: int, pots: int}>
     * }
     */
    function luziapi_audit_loyalty_drift_data(?int $year): array
    {
        global $wpdb;

        $clock = new WordPressClock();
        $schema = new LoyaltySchemaManager($wpdb);
        $ledger = new WordPressLoyaltyLedger($wpdb, $schema, $clock->timezone());

        $handler = new AuditLoyaltyDriftHandler(
            new WooCommerceOrderRepository($clock->timezone()),
            new WooCommerceEligiblePotReader(new WooCommerceEligiblePotCounter()),
            new LoyaltyModuleLedgerReader($ledger),
            $clock,
        );

        $report = $handler->handle(new AuditLoyaltyDriftQuery($year));

        return [
            'year'               => $year,
            'has_drift'          => $report->hasDrift(),
            'anomaly_count'      => $report->anomalyCount(),
            'total_missing_pots' => $report->totalMissingPots(),
            'total_orphan_pots'  => $report->totalOrphanPots(),
            'gaps'               => array_map(
                static fn (LoyaltyCreditGap $g): array => ['order' => $g->orderNumber, 'id' => $g->orderId, 'pots' => $g->eligiblePots],
                $report->creditGaps,
            ),
            'orphans'            => array_map(
                static fn (OrphanLoyaltyCredit $o): array => ['order_id' => $o->orderId, 'pots' => $o->netPots],
                $report->orphanCredits,
            ),
        ];
    }
}
