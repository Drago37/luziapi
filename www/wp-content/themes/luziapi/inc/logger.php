<?php

/**
 * Journalisation applicative LuziApi (Monolog, PSR-3).
 *
 * Stratégie « fingers crossed » : à chaque requête on bufferise tout (jusqu'aux
 * notices/warnings), mais on n'écrit dans `wp-content/luziapi-logs/prod.log` que
 * si un `ERROR` (ou pire) survient — le buffer part alors comme contexte. Une
 * requête sans erreur n'écrit donc RIEN (fini l'inondation du log). Le dossier
 * est hors du thème, auto-créé et protégé par `.htaccess` (jamais servi en HTTP).
 *
 * `luziapi_register_error_handler()` route les erreurs PHP, les exceptions non
 * attrapées et les fatals dans Monolog : on n'utilise plus le `error_log` natif.
 */

declare(strict_types=1);

use LuziApi\Support\WordPressMailerHandler;
use Monolog\ErrorHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Logger PSR-3 partagé du thème. Utilisable depuis le procédural (`inc/`) comme
 * injecté dans les adaptateurs (Infrastructure).
 */
function luziapi_logger(): LoggerInterface
{
    static $logger = null;
    if ($logger instanceof LoggerInterface) {
        return $logger;
    }

    $logger = new Logger('luziapi');

    // Contexte enrichi. Le WebProcessor n'a de sens qu'en contexte HTTP.
    if ('cli' !== PHP_SAPI && isset($_SERVER['REQUEST_URI'])) {
        $logger->pushProcessor(new WebProcessor(null, [
            'url' => 'REQUEST_URI',
            'http_method' => 'REQUEST_METHOD',
            'ip' => 'REMOTE_ADDR',
            'referrer' => 'HTTP_REFERER',
            'user_agent' => 'HTTP_USER_AGENT',
        ]));
    }
    // Fichier/ligne/classe à partir de WARNING (les niveaux plus bas restent légers).
    $logger->pushProcessor(new IntrospectionProcessor(Level::Warning, ['Monolog\\', 'luziapi_logger']));
    $logger->pushProcessor(new MemoryPeakUsageProcessor());
    $logger->pushProcessor(new PsrLogMessageProcessor());

    try {
        $directory = WP_CONTENT_DIR . '/luziapi-logs';
        if (! is_dir($directory)) {
            wp_mkdir_p($directory);
            // Apache / LiteSpeed (o2switch) honorent le .htaccess : dossier privé.
            @file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($directory . '/index.html', '');
        }
        // Rotation quotidienne (prod-YYYY-MM-DD.log), 14 jours conservés : borne
        // la taille même en cas de tempête d'erreurs.
        $stream = new RotatingFileHandler($directory . '/prod.log', 14, Level::Debug);
        $stream->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true,
        ));
        // On n'écrit que lorsqu'un ERROR est atteint ; le buffer (jusqu'à 200
        // enregistrements) est alors vidé comme contexte, puis on continue à
        // écrire jusqu'à la fin de la requête.
        $logger->pushHandler(new FingersCrossedHandler(
            $stream,
            new ErrorLevelActivationStrategy(Level::Error),
            200,
        ));

        // Alerte e-mail sur ERROR (via wp_mail, signée DKIM), au plus une toutes
        // les 2 minutes pour ne pas transformer une panne en tempête d'e-mails.
        $mailer = new WordPressMailerHandler(
            'luziapi37150@gmail.com',
            'LuziApi — erreur en production',
            $directory . '/alert-throttle',
            120,
            Level::Error,
        );
        $mailer->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message%\n%context%\n%extra%\n",
            'Y-m-d H:i:s',
            true,
            true,
        ));
        $logger->pushHandler($mailer);
    } catch (\Throwable) {
        // La journalisation ne doit jamais casser le site.
        $logger->pushHandler(new NullHandler());
    }

    return $logger;
}

/**
 * Route les erreurs PHP, les exceptions non attrapées et les fatals dans Monolog,
 * et coupe l'écriture native dans `error_log` (on ne s'appuie plus dessus).
 * Idempotent.
 */
function luziapi_register_error_handler(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    ErrorHandler::register(luziapi_logger());
    // Plus de fichier error_log natif ni d'affichage d'erreur au visiteur :
    // tout passe par Monolog (fingers crossed → prod.log si ERROR).
    @ini_set('log_errors', '0');
    @ini_set('display_errors', '0');
}
