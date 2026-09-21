<?php

/**
 * Test d'intégration LOCAL de performance du registre des recettes sur un
 * historique volumineux (20 000 lignes). Exécution : make e2e-pilotage-perf-local.
 * Copie temporaire uniquement, aucune donnée réelle touchée.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-pilotage-perf.php';

$run = luziapi_e2e_pilotage_perf_run();

foreach ($run['results'] as $result) {
    WP_CLI::log(($result['ok'] ? '✓' : '✗') . ' ' . $result['label'] . ('' !== $result['detail'] ? ' — ' . $result['detail'] : ''));
}
WP_CLI::log('Nettoyage : ' . $run['cleanup']);

if (null !== $run['fatal']) {
    WP_CLI::error('Erreur fatale : ' . $run['fatal']);
}
$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));
if ($failed > 0) {
    WP_CLI::error($failed . ' assertion(s) en échec.');
}
WP_CLI::success(count($run['results']) . ' assertions OK — performance du registre validée.');
