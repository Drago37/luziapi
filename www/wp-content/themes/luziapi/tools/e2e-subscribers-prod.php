<?php

/**
 * Test e2e du répertoire d'abonnés (Brevo, lecture seule) sur la PRODUCTION (script à
 * jeton, usage unique).
 *
 * Déposé à la racine du thème sous `_e2e-subscribers.php` par
 * `scripts/e2e-subscribers-prod.sh`, aux côtés du cœur partagé `_e2e-subscribers-core.php`
 * (car `tools/` n'existe pas en prod). Appelé en HTTPS avec le jeton, puis les deux
 * fichiers sont supprimés.
 *
 * Sûr pour la prod : les appels Brevo sont INTERCEPTÉS (contacts factices), aucune
 * requête réseau ne sort, la vraie clé et les vrais contacts ne sont pas touchés, et
 * le cache est purgé — voir l'en-tête de `tools/e2e-subscribers.php`.
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

require __DIR__ . '/_e2e-subscribers-core.php';

$run = luziapi_e2e_subscribers_run();
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));

echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $run['fatal'],
    'summary'     => sprintf('%d/%d assertions', count($run['results']) - $failed, count($run['results'])),
    'results'     => $run['results'],
    'cleanup'     => $run['cleanup'],
    'fatal_error' => $run['fatal'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
