<?php

/**
 * Test d'intégration LOCAL de l'écriture d'abonnement Brevo (inscription/désinscription,
 * appels Brevo interceptés). Exécution : make e2e-subscription-write-local. Aucune requête
 * réseau réelle, aucun contact touché.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-subscription-write.php';

$run = luziapi_e2e_subscription_write_run();

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
WP_CLI::success(count($run['results']) . ' assertions OK — écriture d’abonnement Brevo validée.');
