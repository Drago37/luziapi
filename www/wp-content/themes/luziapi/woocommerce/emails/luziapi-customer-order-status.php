<?php

/**
 * E-mail HTML d'étape métier d'une commande LuziApi.
 *
 * @var \WC_Order                   $order
 * @var string                      $email_heading
 * @var string                      $email_label
 * @var string                      $additional_content
 * @var bool                        $sent_to_admin
 * @var bool                        $plain_text
 * @var \Luziapi_Order_Status_Email $email
 * @var list<string>                $message_lines
 * @var string                      $closing_line
 * @var string                      $newsletter_url
 * @var string                      $site_url
 * @var string                      $logo_url
 * @var array<string, string>       $contact
 * @var string                      $cgv_url
 * @var string                      $cgv_version
 * @var string                      $withdrawal_url
 * @var string                      $mediation_url
 * @var string                      $mediator_url
 * @var string                      $tracking_url
 * @var array{net_pots:int, rewards_available:int, pots_toward_next:int, pots_until_next:int, pots_per_reward:int}|null $loyalty
 * @var array{reward_threshold:int, next:int, lifetime_years:int}|null $loyalty_reminder
 */

defined('ABSPATH') || exit;

$firstName = trim((string) $order->get_billing_first_name());
$highlight_text = isset($highlight_text) ? trim((string) $highlight_text) : '';
$action_url     = isset($action_url) ? trim((string) $action_url) : '';
$action_label   = isset($action_label) ? trim((string) $action_label) : '';
$tracking_url   = isset($tracking_url) ? trim((string) $tracking_url) : '';
$loyalty        = isset($loyalty) && is_array($loyalty) ? $loyalty : null;
$loyalty_reminder = isset($loyalty_reminder) && is_array($loyalty_reminder) ? $loyalty_reminder : null;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($email_heading); ?></title>
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
                    <td class="luziapi-email-header" align="center">
                        <a href="<?php echo esc_url($site_url); ?>" target="_blank" style="display:inline-block;text-decoration:none;">
                            <img class="luziapi-email-logo" src="<?php echo esc_url($logo_url); ?>" width="150" alt="LuziApi — Miel artisanal à Luzillé">
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-hero">
                        <p class="luziapi-email-eyebrow"><?php echo esc_html($email_label); ?></p>
                        <h1 class="luziapi-email-title"><?php echo esc_html($email_heading); ?></h1>
                        <p class="luziapi-email-greeting">
                            <?php if ('' !== $firstName) : ?>
                                Bonjour <?php echo esc_html($firstName); ?>,
                            <?php else : ?>
                                Bonjour,
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-copy">
                        <?php foreach ($message_lines as $line) : ?>
                            <p><?php echo esc_html($line); ?></p>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php if ('' !== $highlight_text) : ?>
                    <tr>
                        <td class="luziapi-email-callout-wrap">
                            <div class="luziapi-email-callout">
                                <?php
                                $safeNote = wc_wptexturize_order_note($highlight_text);
                                echo wp_kses_post(wpautop(make_clickable($safeNote)));
                                ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ('' !== $action_url && '' !== $action_label) : ?>
                    <tr>
                        <td class="luziapi-email-action" align="center">
                            <a class="luziapi-email-button" href="<?php echo esc_url($action_url); ?>"><?php echo esc_html($action_label); ?></a>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td>
                        <div class="luziapi-email-summary">
                            <?php do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email); ?>
                        </div>
                    </td>
                </tr>
                <?php if (! empty($loyalty)) : ?>
                    <tr>
                        <td class="luziapi-email-loyalty">
                            <p class="luziapi-email-eyebrow">Fidélité LuziApi</p>
                            <?php if ($loyalty['rewards_available'] > 0) : ?>
                                <p>🎉 <strong><?php echo esc_html((string) $loyalty['rewards_available']); ?> pot<?php echo $loyalty['rewards_available'] > 1 ? 's' : ''; ?> offert<?php echo $loyalty['rewards_available'] > 1 ? 's' : ''; ?> à réclamer !</strong> Signalez-le à LuziApi lors de votre prochaine commande pour en profiter.</p>
                            <?php elseif ($loyalty['net_pots'] > 0) : ?>
                                <p><strong><?php echo esc_html((string) $loyalty['net_pots']); ?> pot<?php echo $loyalty['net_pots'] > 1 ? 's' : ''; ?> de fidélité</strong> — plus que <?php echo esc_html((string) $loyalty['pots_until_next']); ?> avant un pot offert (<?php echo esc_html((string) $loyalty['pots_per_reward']); ?> pots achetés, le suivant est offert).</p>
                            <?php else : ?>
                                <p><strong><?php echo esc_html((string) $loyalty['pots_per_reward']); ?> pots achetés, le suivant offert.</strong> Chaque pot de miel acheté est compté automatiquement.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if (! empty($loyalty_reminder)) : ?>
                    <tr>
                        <td class="luziapi-email-loyalty">
                            <p class="luziapi-email-eyebrow">Fidélité LuziApi</p>
                            <p>Vous cumulez des pots à chaque achat : <strong><?php echo esc_html((string) $loyalty_reminder['reward_threshold']); ?> pots achetés, le <?php echo esc_html((string) $loyalty_reminder['next']); ?>e offert.</strong> Les pots de cette commande seront comptés dès qu’elle sera terminée. Vos pots sont valables <?php echo esc_html((string) $loyalty_reminder['lifetime_years']); ?> ans.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ('' !== $tracking_url) : ?>
                    <tr>
                        <td class="luziapi-email-action" align="center">
                            <a class="luziapi-email-button" href="<?php echo esc_url($tracking_url); ?>">Suivre ma commande</a>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="luziapi-email-customer">
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
                    <td class="luziapi-email-newsletter-wrap">
                        <table class="luziapi-email-newsletter" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
                            <tr>
                                <td>
                                    <strong aria-hidden="true">✉</strong>&nbsp;
                                    Envie de suivre les prochaines récoltes&nbsp;?
                                    <a href="<?php echo esc_url($newsletter_url); ?>">Inscrivez-vous aux actualités LuziApi</a>
                                    par e-mail et/ou SMS — c’est gratuit et sans engagement.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-signoff">
                        <p><?php echo esc_html($closing_line); ?></p>
                        <p class="luziapi-email-signature">Anthony — LuziApi</p>
                    </td>
                </tr>
                <tr>
                    <td class="luziapi-email-footer">
                        <p class="luziapi-email-footer-brand">Luzi<span>Api</span></p>
                        <p class="luziapi-email-footer-tagline">Miel artisanal récolté, extrait et mis en pot à Luzillé, au cœur de l’Indre-et-Loire.</p>
                        <p class="luziapi-email-footer-contact">
                            <?php echo esc_html($contact['nom']); ?> — apiculteur<br>
                            <?php echo esc_html($contact['adresse']); ?>, <?php echo esc_html($contact['cp_ville']); ?><br>
                            <a href="tel:<?php echo esc_attr($contact['tel_lien']); ?>"><?php echo esc_html($contact['tel']); ?></a>
                            · <a href="mailto:<?php echo esc_attr($contact['email']); ?>"><?php echo esc_html($contact['email']); ?></a>
                        </p>
                        <p class="luziapi-email-footer-company">
                            SIREN 811 203 314 · SIRET 811 203 314 00026<br>
                            TVA non applicable, article 293 B du Code général des impôts
                        </p>
                        <div class="luziapi-email-footer-links">
                            <a href="<?php echo esc_url($site_url); ?>">Le site</a>&nbsp;&nbsp;·&nbsp;&nbsp;
                            <?php if ('' !== $cgv_url) : ?>
                                <a href="<?php echo esc_url($cgv_url); ?>">Conditions générales de vente<?php if ('' !== $cgv_version) : ?> (version <?php echo esc_html($cgv_version); ?>)<?php endif; ?></a>&nbsp;&nbsp;·&nbsp;&nbsp;
                            <?php endif; ?>
                            <?php if ('' !== $withdrawal_url) : ?>
                                <a href="<?php echo esc_url($withdrawal_url); ?>">Rétractation</a>&nbsp;&nbsp;·&nbsp;&nbsp;
                            <?php endif; ?>
                            <?php if ('' !== $mediation_url) : ?>
                                <a href="<?php echo esc_url($mediation_url); ?>">Médiation</a>
                            <?php endif; ?>
                        </div>
                        <p class="luziapi-email-footer-mediator">
                            Médiateur de la consommation&nbsp;: CM2C — 49 rue de Ponthieu, 75008 Paris · 01 89 47 00 14 ·
                            <a href="<?php echo esc_url($mediator_url); ?>">www.cm2c.net</a>
                        </p>
                        <p class="luziapi-email-social">
                            <a href="<?php echo esc_url($contact['facebook']); ?>"><span class="luziapi-email-social-icon">f</span>Facebook</a>
                            &nbsp;&nbsp;
                            <a href="<?php echo esc_url($contact['instagram']); ?>"><span class="luziapi-email-social-icon">◎</span>Instagram</a>
                        </p>
                        <p class="luziapi-email-footer-automatic">
                            Cet e-mail concerne votre commande et a été envoyé automatiquement depuis une adresse «&nbsp;no-reply&nbsp;».
                            Vous pouvez néanmoins y répondre&nbsp;: votre message sera adressé à
                            <a href="mailto:<?php echo esc_attr($contact['email']); ?>"><?php echo esc_html($contact['email']); ?></a>.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
