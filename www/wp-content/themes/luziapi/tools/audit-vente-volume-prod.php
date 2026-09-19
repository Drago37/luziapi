<?php

/**
 * Audit de la remise de volume sur les commandes Vente en PRODUCTION (script à jeton).
 *
 * Déposé à la racine du thème sous `_audit-vente-volume.php` par
 * `scripts/audit-vente-volume-prod.sh`, aux côtés du cœur partagé
 * `_audit-vente-volume-core.php` (car `tools/` n'existe pas en prod). Appelé en HTTPS
 * avec le jeton, puis les deux fichiers sont supprimés.
 *
 * Lecture seule : aucune écriture, aucun e-mail.
 *
 * Sortie : JSON { mode, has_drift, anomaly_count, total_missing_cents, missing }.
 */

declare(strict_types=1);

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

if (! function_exists('wc_get_orders')) {
    echo json_encode(['fatal_error' => 'WooCommerce inactif', 'has_drift' => false]);
    exit;
}

require __DIR__ . '/_audit-vente-volume-core.php';

$yearParam = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$data = luziapi_audit_vente_volume_data(0 !== $yearParam ? $yearParam : null);

echo json_encode(['mode' => 'prod'] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
