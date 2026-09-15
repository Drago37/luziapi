<?php

/**
 * Audit de dérive du programme de fidélité, en LOCAL (WP-CLI).
 *
 *   make audit-loyalty-local                              (audite tout l'historique)
 *   LUZIAPI_AUDIT_YEAR=2026 make audit-loyalty-local      (une année civile)
 *
 * Lecture seule : liste deux familles d'anomalie — commande admissible « Terminée »
 * jamais créditée (trou de crédit, à corriger par le backfill ou la case « admissible »),
 * et crédit orphelin (commande disparue encore positive au journal). Sort en erreur si
 * dérive détectée (utile pour un cron ou un contrôle avant backfill).
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_get_orders')) {
    WP_CLI::error('WooCommerce doit être actif pour auditer la fidélité.');
}

require __DIR__ . '/audit-loyalty-drift-core.php';

$yearEnv = (string) getenv('LUZIAPI_AUDIT_YEAR');
$year = '' !== $yearEnv ? (int) $yearEnv : null;

$data = luziapi_audit_loyalty_drift_data($year);

$scope = null !== $data['year'] ? "l'année {$data['year']}" : "tout l'historique";
WP_CLI::log(sprintf('Audit de dérive fidélité — %s', $scope));

if (! $data['has_drift']) {
    WP_CLI::success('Aucune dérive : chaque commande admissible est créditée, aucun crédit orphelin.');

    return;
}

foreach ($data['gaps'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Commande %s (#%d) admissible non créditée : %d pot(s) manquant(s) au journal',
        $row['order'],
        $row['id'],
        $row['pots'],
    ));
}
foreach ($data['orphans'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Crédit orphelin : commande #%d disparue, %d pot(s) encore au journal',
        $row['order_id'],
        $row['pots'],
    ));
}

WP_CLI::error(sprintf(
    '%d anomalie(s) : %d pot(s) non crédité(s), %d pot(s) orphelin(s).',
    $data['anomaly_count'],
    $data['total_missing_pots'],
    $data['total_orphan_pots'],
));
