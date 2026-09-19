<?php

/**
 * Runner PROD (script à jeton, usage unique) de l'inspection fidélité d'un client.
 *
 * Déposé à la racine du thème sous `_loyalty-customer-inspect.php` par
 * `scripts/loyalty-customer-inspect-prod.sh`, aux côtés du cœur partagé
 * `loyalty-customer-inspect.php` (uploadé au runtime, `tools/` n'étant pas déployé).
 *
 * LECTURE SEULE : aucune écriture, aucun e-mail. Le terme de recherche vient de `q`.
 *
 * Sortie : JSON du rapport d'inspection.
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
    echo json_encode(['error' => 'WooCommerce inactif']);
    exit;
}

require_once __DIR__ . '/loyalty-customer-inspect.php';
if (! function_exists('luziapi_loyalty_customer_inspect')) {
    echo json_encode(['error' => 'Cœur de l\'inspection absent (loyalty-customer-inspect.php non déposé).']);
    exit;
}

$search = isset($_GET['q']) ? (string) $_GET['q'] : '';
echo json_encode(['mode' => 'prod'] + luziapi_loyalty_customer_inspect($search), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
