<?php

/**
 * Notification interne LuziApi pour une commande WooCommerce.
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
$event = [
    'new_order' => [
        'label'   => 'Nouvelle commande',
        'title'   => 'Une commande est à traiter',
        'message' => 'Une nouvelle commande vient d’être passée par %s.',
        'tone'    => 'new',
    ],
    'cancelled_order' => [
        'label'   => 'Commande annulée',
        'title'   => 'Une commande a été annulée',
        'message' => 'La commande n°%s de %s a été annulée.',
        'tone'    => 'cancelled',
    ],
    'failed_order' => [
        'label'   => 'Paiement échoué',
        'title'   => 'Un paiement a échoué',
        'message' => 'Le paiement de la commande n°%s de %s a échoué.',
        'tone'    => 'failed',
    ],
][$emailId] ?? [
    'label'   => 'Commande',
    'title'   => $email_heading,
    'message' => 'La commande n°%s nécessite votre attention.',
    'tone'    => 'default',
];

$customerName = trim((string) $order->get_formatted_billing_full_name());
if ('' === $customerName) {
    $customerName = 'un client';
}

$orderNumber = (string) $order->get_order_number();
$message = 'new_order' === $emailId
    ? sprintf($event['message'], $customerName)
    : sprintf($event['message'], $orderNumber, $customerName);
$shippingMethod = trim((string) $order->get_shipping_method());
$paymentMethod  = trim((string) $order->get_payment_method_title());
$orderUrl       = (string) $order->get_edit_order_url();
$siteUrl        = home_url('/');
$logoUrl        = LUZIAPI_URI . '/assets/img/logo-email.png';

$formatOrderTotals = static function (array $totals, $totalsOrder) use ($order): array {
    if ($totalsOrder !== $order || ! isset($totals['shipping'])) {
        return $totals;
    }

    $totals['shipping']['label'] = 'Mode de remise :';
    unset($totals['shipping']['meta']);

    return $totals;
};
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($event['title']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&amp;family=Hanken+Grotesk:wght@400;500;600;700&amp;display=swap">
</head>
<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
<table id="luziapi-email-outer" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
    <tr>
        <td id="luziapi-email-shell" align="center" style="padding:28px 16px 48px;">
            <table id="luziapi-email-card" border="0" cellpadding="0" cellspacing="0" width="600" role="presentation">
                <tr>
                    <td class="luziapi-email-header luziapi-admin-header" align="center">
                        <a href="<?php echo esc_url($siteUrl); ?>" target="_blank" style="display:inline-block;text-decoration:none;">
                            <img class="luziapi-email-logo" src="<?php echo esc_url($logoUrl); ?>" width="112" alt="LuziApi">
                        </a>
                        <p class="luziapi-admin-internal">Notification interne</p>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-hero luziapi-admin-hero luziapi-admin-tone-<?php echo esc_attr($event['tone']); ?>">
                        <p class="luziapi-email-eyebrow"><?php echo esc_html($event['label']); ?></p>
                        <h1 class="luziapi-email-title"><?php echo esc_html($event['title']); ?></h1>
                        <p class="luziapi-admin-intro"><?php echo esc_html($message); ?></p>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-admin-facts-wrap">
                        <table class="luziapi-admin-facts" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
                            <tr>
                                <td>
                                    <span>Commande</span>
                                    <strong>#<?php echo esc_html($orderNumber); ?></strong>
                                </td>
                                <td>
                                    <span>Total</span>
                                    <strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span>Mode de remise</span>
                                    <strong><?php echo esc_html('' !== $shippingMethod ? $shippingMethod : 'À vérifier'); ?></strong>
                                </td>
                                <td>
                                    <span>Paiement</span>
                                    <strong><?php echo esc_html('' !== $paymentMethod ? $paymentMethod : 'À vérifier'); ?></strong>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-admin-action-wrap" align="center">
                        <a class="luziapi-admin-action" href="<?php echo esc_url($orderUrl); ?>">Ouvrir la commande</a>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div class="luziapi-email-summary luziapi-admin-summary">
                            <?php
                            add_filter('woocommerce_get_order_item_totals', $formatOrderTotals, 20, 2);
                            try {
                                do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
                            } finally {
                                remove_filter('woocommerce_get_order_item_totals', $formatOrderTotals, 20);
                            }
                            ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-customer luziapi-admin-customer">
                        <?php
                        do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
                        do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);
                        ?>
                    </td>
                </tr>
                <?php if ('' !== trim($additional_content)) : ?>
                    <tr>
                        <td class="luziapi-email-additional">
                            <?php echo wp_kses_post(wpautop(wptexturize($additional_content))); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="luziapi-email-footer luziapi-admin-footer">
                        <p class="luziapi-email-footer-brand">Luzi<span>Api</span></p>
                        <p>Notification interne générée automatiquement par la boutique.</p>
                        <p><a href="<?php echo esc_url($orderUrl); ?>">Voir la commande #<?php echo esc_html($orderNumber); ?> dans WooCommerce</a></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
