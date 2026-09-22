<?php

/**
 * Cœur partagé de l'audit de dérive recette ↔ commandes.
 *
 * Construit le handler à partir des adaptateurs réels (dépôt de recettes NON audité,
 * lecture seule) et renvoie un tableau sérialisable décrivant la dérive de la période.
 * Utilisé par l'outil local (`tools/audit-receipt-drift.php`, rendu texte) et par le
 * wrapper prod à jeton (`tools/audit-receipt-drift-prod.php`, rendu JSON).
 *
 * Lecture seule : aucune écriture, aucun e-mail.
 */

declare(strict_types=1);

use LuziApi\Shop\Application\Query\AuditReceiptDrift\AuditReceiptDriftHandler;
use LuziApi\Shop\Application\Query\AuditReceiptDrift\AuditReceiptDriftQuery;
use LuziApi\Shop\Domain\Receipt\OrphanReceipt;
use LuziApi\Shop\Domain\Receipt\ReceiptDriftAuditor;
use LuziApi\Shop\Domain\Receipt\ReceiptReconciliation;
use LuziApi\Shop\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Shop\Infrastructure\WordPress\ShopSchemaManager;
use LuziApi\Shared\Infrastructure\WordPress\WordPressClock;
use LuziApi\Shop\Infrastructure\WordPress\WordPressReceiptRepository;

if (! function_exists('luziapi_audit_receipt_drift_data')) {
    /**
     * @return array{
     *     year: int|null,
     *     has_drift: bool,
     *     anomaly_count: int,
     *     total_drift_cents: int,
     *     missing: list<array{order: string, id: int, expected: int, recorded: int, difference: int}>,
     *     divergent: list<array{order: string, id: int, expected: int, recorded: int, difference: int}>,
     *     orphans: list<array{order_id: int, net: int}>
     * }
     */
    function luziapi_audit_receipt_drift_data(?int $year): array
    {
        global $wpdb;

        $clock = new WordPressClock();
        $schema = new ShopSchemaManager($wpdb);
        $handler = new AuditReceiptDriftHandler(
            new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()),
            new WooCommerceOrderRepository($clock->timezone()),
            new ReceiptReconciliationProjector(),
            new ReceiptDriftAuditor(),
            $clock,
        );

        $report = $handler->handle(new AuditReceiptDriftQuery($year));

        $reconciliationRow = static fn (ReceiptReconciliation $r): array => [
            'order'      => $r->order->number,
            'id'         => $r->order->id,
            'expected'   => $r->expected->cents(),
            'recorded'   => $r->recorded->cents(),
            'difference' => $r->difference->cents(),
        ];

        return [
            'year'              => $year,
            'has_drift'         => $report->hasDrift(),
            'anomaly_count'     => $report->anomalyCount(),
            'total_drift_cents' => $report->totalDriftCents(),
            'missing'           => array_map($reconciliationRow, $report->missingReceipts),
            'divergent'         => array_map($reconciliationRow, $report->divergentReceipts),
            'orphans'           => array_map(
                static fn (OrphanReceipt $o): array => ['order_id' => $o->orderId, 'net' => $o->net->cents()],
                $report->orphanReceipts,
            ),
        ];
    }
}
