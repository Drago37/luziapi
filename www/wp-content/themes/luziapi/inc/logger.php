<?php

/**
 * Journalisation applicative LuziApi (Monolog, PSR-3).
 *
 * Un unique logger partagé écrit dans `wp-content/luziapi-logs/prod.log`, un
 * dossier créé automatiquement et rendu inaccessible en HTTP (`.htaccess`
 * deny-all + `index.html`) pour ne jamais exposer de données. Niveau WARNING :
 * on ne journalise que les anomalies (échec d'envoi d'e-mail, panne DB…), le
 * fichier reste donc léger. Si le fichier n'est pas inscriptible, on bascule
 * sur un handler nul : la journalisation ne doit jamais casser le site.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Logger PSR-3 partagé du thème. Utilisable depuis le code procédural (`inc/`)
 * comme injecté dans les adaptateurs (Infrastructure).
 */
function luziapi_logger(): \Psr\Log\LoggerInterface
{
    static $logger = null;
    if ($logger instanceof \Psr\Log\LoggerInterface) {
        return $logger;
    }

    $logger = new \Monolog\Logger('luziapi');
    $directory = WP_CONTENT_DIR . '/luziapi-logs';

    try {
        if (! is_dir($directory)) {
            wp_mkdir_p($directory);
            // Apache / LiteSpeed (o2switch) honorent le .htaccess : dossier privé.
            @file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($directory . '/index.html', '');
        }
        $handler = new \Monolog\Handler\StreamHandler($directory . '/prod.log', \Monolog\Level::Warning);
        $handler->setFormatter(new \Monolog\Formatter\LineFormatter(null, 'Y-m-d H:i:s', true, true));
        $logger->pushHandler($handler);
    } catch (\Throwable) {
        $logger->pushHandler(new \Monolog\Handler\NullHandler());
    }

    return $logger;
}
