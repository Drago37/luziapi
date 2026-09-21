<?php

/**
 * Test d'intégration LOCAL du schéma du tableau de pilotage (migration
 * idempotente, nullabilité, contraintes uniques, sauvegarde/restauration).
 * Exécution : make e2e-pilotage-schema-local. Copies temporaires uniquement,
 * aucune donnée réelle touchée.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-pilotage-schema.php';

$run = luziapi_e2e_pilotage_schema_run();

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
WP_CLI::success(count($run['results']) . ' assertions OK — schéma du pilotage validé.');
