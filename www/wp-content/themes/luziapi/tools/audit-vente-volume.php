<?php

/**
 * Audit de la remise de volume sur les commandes Vente, en LOCAL (WP-CLI).
 *
 *   make audit-vente-volume-local                              (tout l'historique)
 *   LUZIAPI_AUDIT_YEAR=2026 make audit-vente-volume-local      (une année civile)
 *
 * Lecture seule : liste les commandes Vente à ≥ 2 pots payés sans la remise
 * « −1 € par pot dès 2 pots » (ou incomplète), avec le montant à rattraper. Sort
 * en erreur si au moins une commande est concernée (utile pour un contrôle régulier).
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_get_orders')) {
    WP_CLI::error('WooCommerce doit être actif pour auditer la remise de volume.');
}

require __DIR__ . '/audit-vente-volume-core.php';

$yearEnv = (string) getenv('LUZIAPI_AUDIT_YEAR');
$year = '' !== $yearEnv ? (int) $yearEnv : null;

$data = luziapi_audit_vente_volume_data($year);

$euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';
$scope = null !== $data['year'] ? "l'année {$data['year']}" : "tout l'historique";

WP_CLI::log(sprintf('Audit de la remise de volume sur les commandes Vente — %s', $scope));

if (! $data['has_drift']) {
    WP_CLI::success('Aucune commande Vente sans remise de volume : tout est correct.');

    return;
}

foreach ($data['missing'] as $row) {
    WP_CLI::log(sprintf(
        '  ⚠  Commande %s (#%d, %s) : %d pots payés, remise manquante %s à rattraper',
        $row['order'],
        $row['id'],
        $row['status'],
        $row['paid_jars'],
        $euros($row['missing_cents']),
    ));
}

WP_CLI::error(sprintf(
    '%d commande(s) à rattraper, remise de volume totale manquante %s.',
    $data['anomaly_count'],
    $euros($data['total_missing_cents']),
));
