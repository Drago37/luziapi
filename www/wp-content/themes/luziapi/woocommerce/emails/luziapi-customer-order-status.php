<?php

/**
 * E-mail HTML d'étape métier d'une commande LuziApi.
 *
 * @var \WC_Order                   $order
 * @var string                      $email_heading
 * @var string                      $additional_content
 * @var bool                        $sent_to_admin
 * @var bool                        $plain_text
 * @var \Luziapi_Order_Status_Email $email
 * @var list<string>                $message_lines
 * @var string                      $newsletter_url
 * @var string                      $cgv_url
 * @var string                      $cgv_version
 * @var string                      $withdrawal_url
 */

defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p>
    <?php if ($order->get_billing_first_name()) : ?>
        Bonjour <?php echo esc_html($order->get_billing_first_name()); ?>,
    <?php else : ?>
        Bonjour,
    <?php endif; ?>
</p>

<?php foreach ($message_lines as $line) : ?>
    <p><?php echo esc_html($line); ?></p>
<?php endforeach; ?>

<?php if ($newsletter_url) : ?>
    <p>
        Pour être informé(e) des actualités de LuziApi et notamment des prochaines récoltes de miel,
        vous pouvez vous inscrire gratuitement à la newsletter et/ou aux alertes SMS :
        <a href="<?php echo esc_url($newsletter_url); ?>">je m’abonne aux actualités de LuziApi</a>.
    </p>
<?php endif; ?>

<?php if ($cgv_url || $withdrawal_url) : ?>
    <p style="font-size:13px;color:#6b5636;">
        <?php if ($cgv_url) : ?>
            <a href="<?php echo esc_url($cgv_url); ?>">Conditions générales de vente</a>
            <?php if ($cgv_version) : ?>(version <?php echo esc_html($cgv_version); ?>)<?php endif; ?>
        <?php endif; ?>
        <?php if ($cgv_url && $withdrawal_url) : ?> · <?php endif; ?>
        <?php if ($withdrawal_url) : ?>
            <a href="<?php echo esc_url($withdrawal_url); ?>">Renoncer au contrat ici</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
