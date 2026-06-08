<?php

/**
 * Page d'accueil (one-page).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$context           = Timber\Timber::context();
$context['post']   = Timber\Timber::get_post();
$context['honeys'] = function_exists('luziapi_get_honeys') ? luziapi_get_honeys() : [];

// Si un formulaire Contact Form 7 est défini, renseigner son shortcode ici, ex. :
// $context['contact_form_shortcode'] = '[contact-form-7 id="123" title="Contact"]';
$context['contact_form_shortcode'] = defined('LUZIAPI_CF7') ? LUZIAPI_CF7 : '';

// Formulaire newsletter (shortcode du plugin d'e-mailing, ex. Brevo/MailPoet) :
// define('LUZIAPI_NEWSLETTER', '[sibwp_form id=1]'); dans wp-config.php
$context['newsletter_shortcode'] = defined('LUZIAPI_NEWSLETTER') ? LUZIAPI_NEWSLETTER : '';

Timber\Timber::render('front-page.twig', $context);
