<?php

/**
 * Thème LuziApi — bootstrap.
 *
 * Charge l'autoload Composer (Timber) puis les fichiers de configuration
 * du thème (convention WordPress : dossier inc/).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LUZIAPI_DIR', get_template_directory());
define('LUZIAPI_URI', get_template_directory_uri());
define('LUZIAPI_VERSION', '1.0.0');

// Autoload Composer (Timber). Lancer `composer install` à la racine du thème.
$autoload = LUZIAPI_DIR . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Garde-fou : Timber est requis (le rendu se fait uniquement via Timber/Twig).
if (! class_exists('Timber\\Timber')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . 'Le thème LuziApi nécessite Timber. Exécutez <code>composer install</code> '
            . 'dans le dossier du thème.'
            . '</p></div>';
    });
    return;
}

Timber\Timber::init();

// Les gabarits Twig du thème vivent dans templates/ (le dossier par défaut de Timber est « views »).
Timber\Timber::$dirname = 'templates';

require_once LUZIAPI_DIR . '/inc/setup.php';
require_once LUZIAPI_DIR . '/inc/timber.php';
require_once LUZIAPI_DIR . '/inc/seo.php';
require_once LUZIAPI_DIR . '/inc/shop.php';
require_once LUZIAPI_DIR . '/inc/woocommerce.php';
