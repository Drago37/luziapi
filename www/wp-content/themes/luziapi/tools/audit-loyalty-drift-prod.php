<?php

/**
 * Audit de dérive du programme de fidélité sur la PRODUCTION (script à jeton, usage unique).
 *
 * Déposé à la racine du thème sous `_audit-loyalty-drift.php` par
 * `scripts/audit-loyalty-prod.sh`, aux côtés du cœur partagé `_audit-loyalty-drift-core.php`
 * (car `tools/` n'existe pas en prod). Appelé en HTTPS avec le jeton, puis les deux
 * fichiers sont supprimés.
 *
 * Lecture seule : aucune écriture, aucun e-mail.
 *
 * Sortie : JSON { mode, has_drift, anomaly_count, total_missing_pots, total_orphan_pots, gaps, orphans }.
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

require __DIR__ . '/_audit-loyalty-drift-core.php';

$yearParam = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$data = luziapi_audit_loyalty_drift_data(0 !== $yearParam ? $yearParam : null);

echo json_encode(['mode' => 'prod'] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
