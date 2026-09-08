<?php

/**
 * Habillage LuziApi des notifications de commande destinées à la boutique.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param mixed $email
 */
function luziapi_is_admin_order_email($email): bool
{
    return $email instanceof \WC_Email
        && in_array($email->id, ['new_order', 'cancelled_order', 'failed_order'], true);
}

/**
 * Les trois notifications WooCommerce utilisent un gabarit interne commun,
 * distinct du message chaleureux et commercial envoyé au client.
 *
 * @param array<string, \WC_Email> $emails
 *
 * @return array<string, \WC_Email>
 */
add_filter('woocommerce_email_classes', static function (array $emails): array {
    foreach (['WC_Email_New_Order', 'WC_Email_Cancelled_Order', 'WC_Email_Failed_Order'] as $className) {
        if (! isset($emails[$className])) {
            continue;
        }

        $emails[$className]->template_html  = 'emails/luziapi-admin-order.php';
        $emails[$className]->template_plain = 'emails/plain/luziapi-admin-order.php';
        $emails[$className]->template_base  = LUZIAPI_DIR . '/woocommerce/';
    }

    return $emails;
}, 30);

/**
 * Ajoute les styles communs puis les règles propres aux notifications internes
 * avant leur transformation en styles inline par WooCommerce.
 *
 * @param mixed $email
 */
add_filter('woocommerce_email_styles', static function (string $css, $email): string {
    if (! luziapi_is_admin_order_email($email)) {
        return $css;
    }

    foreach (['email.css', 'admin-email.css'] as $filename) {
        $path = LUZIAPI_DIR . '/assets/css/' . $filename;
        if (! is_readable($path)) {
            continue;
        }

        $contents = file_get_contents($path);
        if (is_string($contents)) {
            $css .= "\n" . $contents;
        }
    }

    return $css;
}, 30, 2);
