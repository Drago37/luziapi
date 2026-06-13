<?php
/**
 * Plugin Name: LuziApi — Sécurité
 * Description: En-têtes de sécurité HTTP + durcissement WordPress (édition de fichiers interdite, pingbacks XML-RPC neutralisés, métadonnées réduites).
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1) Interdire l'édition de thèmes/plugins depuis l'admin (limite les dégâts si un compte est compromis).
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

// 2) En-têtes de sécurité HTTP (clickjacking, sniffing MIME, fuite de référent, permissions).
add_action('send_headers', function () {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (function_exists('is_ssl') && is_ssl()) {
        // Le site force déjà HTTPS (301) : on demande aux navigateurs de ne plus tenter le HTTP.
        header('Strict-Transport-Security: max-age=31536000');
    }
});

// 3) XML-RPC : neutraliser les pingbacks (amplification DDoS) sans couper Jetpack (connexion signée).
add_filter('xmlrpc_methods', function (array $methods): array {
    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
    return $methods;
});
add_filter('wp_headers', function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
});
add_filter('pings_open', '__return_false');

// 4) Réduire la surface d'information : retirer les métadonnées superflues du <head>.
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');

// 5) Message de connexion générique (ne révèle pas si c'est l'identifiant ou le mot de passe qui est faux).
add_filter('login_errors', function (): string {
    return 'Identifiants incorrects.';
});
