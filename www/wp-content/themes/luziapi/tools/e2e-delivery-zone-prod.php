<?php

/**
 * Test e2e de la validation de zone de livraison sur la PRODUCTION (script à
 * jeton, à usage unique). Déposé à la racine du thème sous `_e2e-delivery-zone.php`
 * par `scripts/e2e-delivery-zone-prod.sh`, aux côtés du cœur
 * `_e2e-delivery-zone-core.php`. Appelé en HTTPS avec le jeton, puis les deux
 * fichiers sont supprimés.
 *
 * SÛR : aucune commande/produit, aucun e-mail — n'exécute que le hook de
 * validation avec un `WP_Error` en mémoire (voir `tools/e2e-delivery-zone.php`).
 *
 * Sortie : JSON { mode, all_passed, summary, results, cleanup, fatal_error }.
 */

declare(strict_types=1);

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

require __DIR__ . '/_e2e-delivery-zone-core.php';

$run = luziapi_e2e_delivery_zone_run();
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));

echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $run['fatal'],
    'summary'     => sprintf('%d/%d assertions', count($run['results']) - $failed, count($run['results'])),
    'results'     => $run['results'],
    'cleanup'     => $run['cleanup'],
    'fatal_error' => $run['fatal'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
