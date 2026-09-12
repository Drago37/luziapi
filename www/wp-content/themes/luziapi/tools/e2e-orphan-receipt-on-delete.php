<?php

/**
 * Test d'intégration LOCAL : la recette est retirée du registre quand la commande
 * est mise à la corbeille ou supprimée.
 *
 * Exécution : make e2e-orphan-receipt-local
 *
 * Aucune donnée laissée en base, même en cas d'échec.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

require __DIR__ . '/e2e-orphan-receipt.php';

$run = luziapi_e2e_orphan_receipt_run();

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

WP_CLI::success(count($run['results']) . ' assertions OK — retrait des recettes à la corbeille/suppression validé.');
