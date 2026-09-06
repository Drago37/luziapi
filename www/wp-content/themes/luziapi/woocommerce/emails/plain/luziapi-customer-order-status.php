<?php

/**
 * E-mail texte brut d'étape métier d'une commande LuziApi.
 *
 * @var \WC_Order                   $order
 * @var string                      $email_heading
 * @var string                      $additional_content
 * @var bool                        $sent_to_admin
 * @var bool                        $plain_text
 * @var \Luziapi_Order_Status_Email $email
 * @var list<string>                $message_lines
 * @var string                      $newsletter_url
 */

defined('ABSPATH') || exit;

echo "==========\n";
echo esc_html($email_heading) . "\n";
echo "==========\n\n";

$firstName = $order->get_billing_first_name();
echo '' !== $firstName ? 'Bonjour ' . esc_html($firstName) . ",\n\n" : "Bonjour,\n\n";

foreach ($message_lines as $line) {
    echo esc_html($line) . "\n\n";
}

if ($newsletter_url) {
    echo "Pour être informé(e) des actualités de LuziApi et notamment des prochaines récoltes de miel,\n";
    echo "vous pouvez vous inscrire gratuitement à la newsletter et/ou aux alertes SMS :\n";
    echo esc_url($newsletter_url) . "\n\n";
}

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

if ($additional_content) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}

echo "\n----------------------------------------\n\n";
do_action('woocommerce_email_footer', $email);
