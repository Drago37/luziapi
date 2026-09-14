<?php

/**
 * Test e2e « écriture d'abonnement Brevo » sur la PRODUCTION (script à jeton, usage unique).
 * Déposé sous `_e2e-subscription-write.php` avec le cœur `_e2e-subscription-write-core.php`.
 * Appels Brevo interceptés (réponse factice), aucune requête réelle, aucun contact touché.
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

require __DIR__ . '/_e2e-subscription-write-core.php';

$run = luziapi_e2e_subscription_write_run();
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));

echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $run['fatal'],
    'summary'     => sprintf('%d/%d assertions', count($run['results']) - $failed, count($run['results'])),
    'results'     => $run['results'],
    'cleanup'     => $run['cleanup'],
    'fatal_error' => $run['fatal'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
