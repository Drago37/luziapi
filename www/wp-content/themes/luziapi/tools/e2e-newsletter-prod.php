<?php

/**
 * Test e2e de l'auto-envoi newsletter sur la PRODUCTION (script à jeton, à usage
 * unique). Déposé à la racine du thème sous `_e2e-newsletter.php` par
 * `scripts/e2e-newsletter-prod.sh`, aux côtés du cœur `_e2e-newsletter-core.php`
 * (car `tools/` n'existe pas en prod). Appelé en HTTPS avec le jeton, puis les
 * deux fichiers sont supprimés.
 *
 * SÛR pour la prod : aucun e-mail ni SMS ne part — le seul canal (Brevo en HTTP)
 * est intercepté avant tout départ, la clé API n'est pas lue, et l'article de test
 * est supprimé + l'event dé-planifié. Voir l'en-tête de `tools/e2e-newsletter.php`.
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

require __DIR__ . '/_e2e-newsletter-core.php';

$run = luziapi_e2e_newsletter_run();
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));

echo json_encode([
    'mode'        => 'prod',
    'all_passed'  => 0 === $failed && null === $run['fatal'],
    'summary'     => sprintf('%d/%d assertions', count($run['results']) - $failed, count($run['results'])),
    'results'     => $run['results'],
    'cleanup'     => $run['cleanup'],
    'fatal_error' => $run['fatal'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
