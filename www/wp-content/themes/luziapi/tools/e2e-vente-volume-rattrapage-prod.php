<?php

/**
 * Test e2e « Rattrapage de la remise de volume » sur la PRODUCTION (script à jeton,
 * à usage unique).
 *
 * Déposé à la racine du thème sous `_e2e-vente-volume-rattrapage.php` par
 * `scripts/e2e-vente-volume-rattrapage-prod.sh`, aux côtés du cœur partagé
 * `_e2e-vente-volume-rattrapage-core.php` et de l'audit `_audit-vente-volume-core.php`
 * (car `tools/` n'existe pas en prod). Appelé en HTTPS avec le jeton, puis les
 * fichiers sont supprimés.
 *
 * Sûr pour la prod : voir l'en-tête de `tools/e2e-vente-volume-rattrapage-core.php`
 * (produits + commande isolés, aucun e-mail, recette seedée puis nettoyée, tout
 * supprimé).
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

if (! function_exists('wc_create_order')) {
    echo json_encode(['all_passed' => false, 'fatal_error' => 'WooCommerce inactif', 'results' => []]);
    exit;
}

// L'audit-core d'abord (le cœur e2e s'appuie dessus et, en prod, ne peut pas le
// require sous son nom local) puis le cœur e2e.
require __DIR__ . '/_audit-vente-volume-core.php';
require __DIR__ . '/_e2e-vente-volume-rattrapage-core.php';

$run = luziapi_e2e_vente_volume_rattrapage_run();
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));

echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $run['fatal'],
    'summary'     => sprintf('%d/%d assertions', count($run['results']) - $failed, count($run['results'])),
    'results'     => $run['results'],
    'cleanup'     => $run['cleanup'],
    'fatal_error' => $run['fatal'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
