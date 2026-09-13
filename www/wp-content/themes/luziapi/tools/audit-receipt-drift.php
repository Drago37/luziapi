<?php

/**
 * Audit de dérive recette ↔ commandes, en LOCAL (WP-CLI).
 *
 *   make audit-receipts-local                 (audite tout l'historique)
 *   LUZIAPI_AUDIT_YEAR=2026 make audit-receipts-local   (une année civile)
 *
 * Lecture seule : compare, sur la période, les recettes enregistrées aux commandes,
 * et liste trois familles d'anomalie — commande valide sans recette, montant
 * divergent, recette orpheline (commande disparue). Sort en erreur si dérive
 * détectée (utile pour un cron ou un contrôle régulier).
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_get_orders')) {
    WP_CLI::error('WooCommerce doit être actif pour auditer les recettes.');
}

require __DIR__ . '/audit-receipt-drift-core.php';

$yearEnv = (string) getenv('LUZIAPI_AUDIT_YEAR');
$year = '' !== $yearEnv ? (int) $yearEnv : null;

$data = luziapi_audit_receipt_drift_data($year);

$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
$scope = null !== $data['year'] ? "l'année {$data['year']}" : "tout l'historique";

WP_CLI::log(sprintf('Audit de dérive recette ↔ commandes — %s', $scope));

if (! $data['has_drift']) {
    WP_CLI::success('Aucune dérive : recettes et commandes concordent.');

    return;
}

foreach ($data['missing'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Commande %s (#%d) sans recette : attendu %s, enregistré %s',
        $row['order'],
        $row['id'],
        $euros($row['expected']),
        $euros($row['recorded']),
    ));
}
foreach ($data['divergent'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Commande %s (#%d) montant divergent : attendu %s, enregistré %s (écart %s)',
        $row['order'],
        $row['id'],
        $euros($row['expected']),
        $euros($row['recorded']),
        $euros($row['difference']),
    ));
}
foreach ($data['orphans'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Recette orpheline : commande #%d disparue, %s encore au registre',
        $row['order_id'],
        $euros($row['net']),
    ));
}

WP_CLI::error(sprintf(
    '%d anomalie(s), dérive totale %s.',
    $data['anomaly_count'],
    $euros($data['total_drift_cents']),
));
