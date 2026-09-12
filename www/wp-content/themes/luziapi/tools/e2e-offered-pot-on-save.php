<?php

/**
 * Test d'intégration LOCAL du bloc « Ajouter un pot offert » de la fiche commande.
 *
 * Exécution : make e2e-offered-pot-local
 *
 * Pilote le vrai chemin admin (save handler → ajout de ligne 0 € → recalcul fidélité)
 * sur le vrai WordPress/WooCommerce local. Aucune donnée laissée en base, même en cas
 * d'échec.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

require __DIR__ . '/e2e-offered-pot.php';

$run = luziapi_e2e_offered_pot_run();

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

WP_CLI::success(count($run['results']) . ' assertions OK — ajout de pot offert (geste + fidélité) validé.');
