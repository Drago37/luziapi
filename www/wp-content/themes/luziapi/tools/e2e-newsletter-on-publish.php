<?php

/**
 * Test d'intégration LOCAL de l'auto-envoi newsletter (mu-plugin).
 *
 * Exécution : make e2e-newsletter-local (copie d'abord le mu-plugin dans
 * www/wp-content/mu-plugins/ pour qu'il soit chargé en local).
 *
 * Pilote le vrai chemin (publication -> planification, exécution -> envoi Brevo
 * INTERCEPTÉ). Aucun e-mail/SMS ne part ; rien laissé en base.
 */

declare(strict_types=1);

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

require __DIR__ . '/e2e-newsletter.php';

$run = luziapi_e2e_newsletter_run();

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

WP_CLI::success(count($run['results']) . ' assertions OK — planification et envoi (stubé) validés.');
