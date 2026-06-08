<?php

/**
 * Gabarit WooCommerce (boutique, fiche produit, panier, commande).
 *
 * WooCommerce génère son contenu via woocommerce_content() ; on le capture
 * puis on l'injecte dans la mise en page du thème (base.twig).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context         = Timber\Timber::context();
$context['post'] = Timber\Timber::get_post();

ob_start();
woocommerce_content();
$context['wc_content'] = ob_get_clean();

Timber\Timber::render('woocommerce.twig', $context);
