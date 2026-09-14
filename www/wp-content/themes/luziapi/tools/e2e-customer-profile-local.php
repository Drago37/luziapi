<?php

/**
 * Test d'intégration LOCAL de la fiche client dédiée (dépôt réel + schéma + surcharge).
 * Exécution : make e2e-customer-profile-local. Commande de test isolée, aucune
 * notification, tout nettoyé.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-customer-profile.php';

$run = luziapi_e2e_customer_profile_run();

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
WP_CLI::success(count($run['results']) . ' assertions OK — fiche client dédiée validée.');
