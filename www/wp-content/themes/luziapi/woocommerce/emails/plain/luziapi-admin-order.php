<?php

/**
 * Notification interne LuziApi en texte brut.
 *
 * @var \WC_Order $order
 * @var string    $email_heading
 * @var string    $additional_content
 * @var bool      $sent_to_admin
 * @var bool      $plain_text
 * @var \WC_Email $email
 */

defined('ABSPATH') || exit;

$emailId = (string) $email->id;
$label = [
    'new_order'       => 'NOUVELLE COMMANDE',
    'cancelled_order' => 'COMMANDE ANNULÉE',
    'failed_order'    => 'PAIEMENT ÉCHOUÉ',
][$emailId] ?? 'COMMANDE À VÉRIFIER';

$customerName = trim((string) $order->get_formatted_billing_full_name());
$customerName = '' !== $customerName ? $customerName : 'Client sans nom';

echo "LUZIAPI — NOTIFICATION INTERNE\n";
echo "================================\n\n";
echo $label . "\n";
echo wp_strip_all_tags($email_heading) . "\n\n";
echo 'Commande : #' . wp_strip_all_tags((string) $order->get_order_number()) . "\n";
echo 'Client : ' . wp_strip_all_tags($customerName) . "\n";
echo 'Total : ' . wp_strip_all_tags($order->get_formatted_order_total()) . "\n";
echo 'Mode de remise : ' . wp_strip_all_tags((string) $order->get_shipping_method()) . "\n";
echo 'Paiement : ' . wp_strip_all_tags((string) $order->get_payment_method_title()) . "\n";
echo 'Ouvrir la commande : ' . esc_url($order->get_edit_order_url()) . "\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

if ('' !== trim($additional_content)) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}

echo "\n--------------------------------\n";
echo "Notification interne générée automatiquement par la boutique LuziApi.\n";
