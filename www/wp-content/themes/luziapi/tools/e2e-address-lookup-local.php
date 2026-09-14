<?php

/**
 * Test d'intégration LOCAL de l'autocomplétion d'adresse (BAN, lecture seule, appels
 * interceptés). Exécution : make e2e-address-lookup-local. Aucune requête réseau réelle,
 * aucune donnée écrite.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-address-lookup.php';

$run = luziapi_e2e_address_lookup_run();

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
WP_CLI::success(count($run['results']) . ' assertions OK — autocomplétion d’adresse validée.');
