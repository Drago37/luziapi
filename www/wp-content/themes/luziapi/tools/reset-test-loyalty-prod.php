<?php

/**
 * Runner PROD (script à jeton, usage unique) de la remise à zéro de l'isolation
 * fidélité des tests e2e.
 *
 * Déposé à la racine du thème sous `_reset-test-loyalty.php` par
 * `scripts/reset-test-loyalty-prod.sh`, aux côtés du cœur partagé
 * `reset-test-loyalty.php` (uploadé au runtime, `tools/` n'étant pas déployé).
 *
 * DRY-RUN par défaut : n'écrit QUE si `apply=1`. Portée strictement limitée aux
 * clusters d'identité des téléphones de test.
 *
 * Sortie : JSON du rapport de purge.
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

require_once __DIR__ . '/reset-test-loyalty.php';
if (! function_exists('luziapi_reset_test_loyalty')) {
    echo json_encode(['error' => 'Cœur de la purge absent (reset-test-loyalty.php non déposé).']);
    exit;
}

// Téléphones de test : liste fixe par défaut, override possible via `phones` (CSV de
// chiffres uniquement, pour ne jamais viser un contact réel arbitraire).
$phones = LUZIAPI_TEST_LOYALTY_PHONES;
if (isset($_GET['phones']) && '' !== trim((string) $_GET['phones'])) {
    $candidate = array_values(array_filter(array_map('trim', explode(',', (string) $_GET['phones']))));
    $safe = array_values(array_filter($candidate, static fn (string $p): bool => 1 === preg_match('/^\+?[0-9]{6,15}$/', $p)));
    if ($safe !== $candidate) {
        echo json_encode(['error' => 'Téléphone(s) invalide(s) dans « phones » — abandon.']);
        exit;
    }
    $phones = $safe;
}

$apply = '1' === (string) ($_GET['apply'] ?? '');
echo json_encode(
    ['mode' => 'prod', 'apply' => $apply] + luziapi_reset_test_loyalty($phones, $apply),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
);
