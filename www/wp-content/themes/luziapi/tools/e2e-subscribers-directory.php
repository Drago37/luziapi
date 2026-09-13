<?php

/**
 * Test d'intégration LOCAL du répertoire d'abonnés (Brevo, lecture seule).
 *
 * Exécution : make e2e-subscribers-local
 *
 * Exerce le vrai chemin (adaptateur Brevo → parsing → domaine) avec les appels HTTP
 * Brevo INTERCEPTÉS : aucune requête ne sort, rien laissé en base.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-subscribers.php';

$run = luziapi_e2e_subscribers_run();

foreach ($run['results'] as $result) {
    WP_CLI::log(
        ($result['ok'] ? '✓' : '✗') . ' ' . $result['label']
        . ('' !== $result['detail'] ? ' — ' . $result['detail'] : '')
    );
}
WP_CLI::log('Nettoyage : ' . $run['cleanup']);

if (null !== $run['fatal']) {
    WP_CLI::error('Erreur fatale : ' . $run['fatal']);
}

$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));
if ($failed > 0) {
    WP_CLI::error($failed . ' assertion(s) en échec.');
}

WP_CLI::success(count($run['results']) . ' assertions OK — répertoire d’abonnés Brevo validé (appels interceptés).');
