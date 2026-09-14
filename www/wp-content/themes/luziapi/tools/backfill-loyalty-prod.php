<?php

/**
 * Runner du BACKFILL de fidélité sur la PRODUCTION (script à jeton, usage unique).
 *
 * Déposé à la racine du thème sous `_backfill-loyalty.php` par
 * `scripts/backfill-loyalty-prod.sh`, aux côtés du cœur partagé
 * `backfill-loyalty.php` (lui aussi uploadé au runtime, car `tools/` n'est pas
 * déployé). Le script réutilise ainsi la fonction éprouvée `luziapi_backfill_loyalty()`
 * — une seule source de vérité pour une opération qui écrit dans le journal réel.
 *
 * SÉCURITÉ : simulation par défaut. Le rétro-crédit n'écrit QUE si `apply=1` est
 * passé (le script shell exige LUZIAPI_BACKFILL_APPLY=1). Idempotent de toute façon
 * (clé `credit:{orderId}`, INSERT IGNORE) : rejouable sans doublon.
 *
 * Sortie : JSON { mode, dry, orders, credited, pots, already, no_contact, no_pots }.
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

require_once __DIR__ . '/backfill-loyalty.php';
if (! function_exists('luziapi_backfill_loyalty')) {
    echo json_encode(['error' => 'Cœur du backfill absent (backfill-loyalty.php non déposé).']);
    exit;
}

$dry = ! (isset($_GET['apply']) && '1' === (string) $_GET['apply']);
$report = luziapi_backfill_loyalty($dry);

echo json_encode(['mode' => 'prod'] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
