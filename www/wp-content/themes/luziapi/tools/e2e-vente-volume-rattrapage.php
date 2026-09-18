<?php

/**
 * Test d'intégration LOCAL du rattrapage de la remise de volume (bouton de la fiche
 * commande + audit lecture seule).
 *
 * Exécution : make e2e-vente-volume-rattrapage-local
 *
 * Pilote le vrai chemin admin (save handler → luziapi_fix_volume_discount → abonné →
 * fee manquant + correction de recette) sur le vrai WordPress/WooCommerce local, plus
 * l'audit. Aucune donnée laissée en base, même en cas d'échec.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

require __DIR__ . '/e2e-vente-volume-rattrapage-core.php';

$run = luziapi_e2e_vente_volume_rattrapage_run();

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

WP_CLI::success(count($run['results']) . ' assertions OK — rattrapage de la remise de volume validé.');
