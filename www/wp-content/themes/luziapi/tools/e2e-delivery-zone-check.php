<?php

/**
 * Test d'intégration LOCAL de la validation de zone de livraison au checkout.
 *
 * Exécution : make e2e-delivery-zone-local
 *
 * Exécute le vrai hook `woocommerce_after_checkout_validation`. Aucune donnée
 * créée, aucun e-mail.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-delivery-zone.php';

$run = luziapi_e2e_delivery_zone_run();

foreach ($run['results'] as $result) {
    WP_CLI::log(
        ($result['ok'] ? '✓' : '✗') . ' ' . $result['label']
        . ('' !== $result['detail'] ? ' — ' . $result['detail'] : '')
    );
}

if (null !== $run['fatal']) {
    WP_CLI::error('Erreur fatale : ' . $run['fatal']);
}

$failed = count(array_filter($run['results'], static fn (array $r): bool => ! $r['ok']));
if ($failed > 0) {
    WP_CLI::error($failed . ' assertion(s) en échec.');
}

WP_CLI::success(count($run['results']) . ' assertions OK — validation de zone de livraison au checkout.');
