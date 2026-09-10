<?php

/**
 * E-mail texte brut d'étape métier d'une commande LuziApi.
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

echo "LUZIAPI — MIEL ARTISANAL · LUZILLÉ\n";
echo "===================================\n\n";
echo wp_strip_all_tags($email_label) . "\n";
echo wp_strip_all_tags($email_heading) . "\n\n";

$firstName = trim((string) $order->get_billing_first_name());
echo '' !== $firstName ? 'Bonjour ' . wp_strip_all_tags($firstName) . ",\n\n" : "Bonjour,\n\n";

foreach ($message_lines as $line) {
    echo wp_strip_all_tags($line) . "\n\n";
}

$highlight_text = isset($highlight_text) ? trim((string) $highlight_text) : '';
$action_url     = isset($action_url) ? trim((string) $action_url) : '';
$action_label   = isset($action_label) ? trim((string) $action_label) : '';
$tracking_url   = isset($tracking_url) ? trim((string) $tracking_url) : '';
$loyalty        = isset($loyalty) && is_array($loyalty) ? $loyalty : null;
$loyalty_reminder = isset($loyalty_reminder) && is_array($loyalty_reminder) ? $loyalty_reminder : null;

if ('' !== $highlight_text) {
    echo "MESSAGE\n";
    echo "-------\n";
    echo wp_strip_all_tags($highlight_text) . "\n\n";
}

if ('' !== $action_url && '' !== $action_label) {
    echo wp_strip_all_tags($action_label) . ' : ' . esc_url($action_url) . "\n\n";
}

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

if (null !== $loyalty) {
    echo "\nFIDÉLITÉ LUZIAPI\n";
    echo "----------------\n";
    if ($loyalty['rewards_available'] > 0) {
        echo $loyalty['rewards_available'] . ' pot(s) offert(s) à réclamer ! Signalez-le à LuziApi lors de votre prochaine commande.' . "\n";
    } elseif ($loyalty['net_pots'] > 0) {
        echo $loyalty['net_pots'] . ' pot(s) de fidélité — plus que ' . $loyalty['pots_until_next']
            . ' avant un pot offert (' . $loyalty['pots_per_reward'] . ' pots achetés, le suivant est offert).' . "\n";
    } else {
        echo $loyalty['pots_per_reward'] . ' pots achetés, le suivant offert. Chaque pot est compté automatiquement.' . "\n";
    }
}

if (null !== $loyalty_reminder) {
    echo "\nFIDÉLITÉ LUZIAPI\n";
    echo "----------------\n";
    echo 'Vous cumulez des pots à chaque achat : ' . $loyalty_reminder['reward_threshold'] . ' pots achetés, le '
        . $loyalty_reminder['next'] . 'e offert. Les pots de cette commande seront comptés dès qu\'elle sera terminée. '
        . 'Vos pots sont valables ' . $loyalty_reminder['lifetime_years'] . ' ans.' . "\n";
}

if ('' !== $tracking_url) {
    echo "\nSuivre ma commande : " . esc_url($tracking_url) . "\n";
}

if ('' !== trim($additional_content)) {
    echo "\n" . wp_strip_all_tags(wptexturize($additional_content)) . "\n";
}

echo "\nACTUALITÉS LUZIAPI\n";
echo "------------------\n";
echo "Envie de suivre les prochaines récoltes ? Inscrivez-vous aux actualités LuziApi\n";
echo "par e-mail et/ou SMS — c’est gratuit et sans engagement :\n";
echo esc_url($newsletter_url) . "\n\n";

echo wp_strip_all_tags($closing_line) . "\n";
echo "Anthony — LuziApi\n\n";

echo "-----------------------------------\n";
echo "LuziApi — Miel artisanal récolté, extrait et mis en pot à Luzillé.\n";
echo wp_strip_all_tags($contact['nom']) . " — apiculteur\n";
echo wp_strip_all_tags($contact['adresse']) . ', ' . wp_strip_all_tags($contact['cp_ville']) . "\n";
echo wp_strip_all_tags($contact['tel']) . ' · ' . sanitize_email($contact['email']) . "\n";
echo "SIREN 811 203 314 · SIRET 811 203 314 00026\n";
echo "TVA non applicable, article 293 B du Code général des impôts\n\n";

echo 'Le site : ' . esc_url($site_url) . "\n";
if ('' !== $cgv_url) {
    echo 'Conditions générales de vente';
    if ('' !== $cgv_version) {
        echo ' (version ' . wp_strip_all_tags($cgv_version) . ')';
    }
    echo ' : ' . esc_url($cgv_url) . "\n";
}
if ('' !== $withdrawal_url) {
    echo 'Rétractation : ' . esc_url($withdrawal_url) . "\n";
}
if ('' !== $mediation_url) {
    echo 'Médiation : ' . esc_url($mediation_url) . "\n";
}

echo "Médiateur de la consommation : CM2C — 49 rue de Ponthieu, 75008 Paris\n";
echo '01 89 47 00 14 · ' . esc_url($mediator_url) . "\n";
echo 'Facebook : ' . esc_url($contact['facebook']) . "\n";
echo 'Instagram : ' . esc_url($contact['instagram']) . "\n\n";

echo "Cet e-mail concerne votre commande et a été envoyé automatiquement depuis une adresse « no-reply ».\n";
echo 'Vous pouvez néanmoins y répondre : votre message sera adressé à ' . sanitize_email($contact['email']) . ".\n";
