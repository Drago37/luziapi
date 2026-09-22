<?php

/**
 * Répare un environnement de développement LOCAL dont les rôles/capacités
 * WordPress ont dérivé (typiquement une copie de production où l'option
 * `{prefix}user_roles` est absente/mal préfixée) : dans ce cas le rôle
 * `administrator` « n'existe plus » et TOUT `/wp-admin/` renvoie 403, même
 * connecté — ce qui casse notamment le smoke navigateur (`make browser-local`).
 *
 * À n'utiliser qu'en local (WP-CLI). N'a aucun effet en production : c'est un
 * outil de développement, exclu du déploiement (dossier `tools/`).
 */

declare(strict_types=1);

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/schema.php';

// Recrée les rôles standards WordPress (dont administrator + ses capacités).
populate_roles();

// Réinjecte les capacités WooCommerce (edit_shop_orders…) sur les rôles.
if (class_exists('WC_Install')) {
    WC_Install::create_roles();
}

$admin = get_user_by('login', 'admin');
if ($admin instanceof WP_User) {
    $admin->set_role('administrator');
    $admin->add_cap('edit_shop_orders');
    $admin->add_cap('manage_woocommerce');
}

wp_cache_flush();

WP_CLI::success('Environnement local réparé : rôles standards + capacités WooCommerce, admin = administrator.');
