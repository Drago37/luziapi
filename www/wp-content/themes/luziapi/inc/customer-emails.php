<?php

/**
 * Habillage des derniers e-mails client natifs et traçabilité des envois.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param mixed $email
 */
function luziapi_is_native_customer_order_email($email): bool
{
    return $email instanceof \WC_Email
        && in_array(
            $email->id,
            ['customer_failed_order', 'customer_refunded_order', 'customer_note', 'customer_invoice'],
            true
        );
}

/**
 * Les e-mails concernés conservent leurs déclencheurs, objets et réglages
 * WooCommerce. Seuls leurs gabarits HTML et texte brut sont remplacés.
 *
 * @param array<string, \WC_Email> $emails
 *
 * @return array<string, \WC_Email>
 */
add_filter('woocommerce_email_classes', static function (array $emails): array {
    foreach (
        [
            'WC_Email_Customer_Failed_Order',
            'WC_Email_Customer_Refunded_Order',
            'WC_Email_Customer_Note',
            'WC_Email_Customer_Invoice',
        ] as $className
    ) {
        if (! isset($emails[$className])) {
            continue;
        }

        $emails[$className]->template_html              = 'emails/luziapi-customer-order.php';
        $emails[$className]->template_plain             = 'emails/plain/luziapi-customer-order.php';
        $emails[$className]->block_email_editor_enabled = false;
    }

    return $emails;
}, 35);

/**
 * @param mixed $email
 */
add_filter('woocommerce_email_styles', static function (string $css, $email): string {
    if (! luziapi_is_native_customer_order_email($email)) {
        return $css;
    }

    $path = LUZIAPI_DIR . '/assets/css/email.css';
    if (! is_readable($path)) {
        return $css;
    }

    $contents = file_get_contents($path);

    return is_string($contents) ? $css . "\n" . $contents : $css;
}, 30, 2);

/**
 * Données communes au pied de page des e-mails client.
 *
 * @return array<string, mixed>
 */
function luziapi_customer_email_common_data(?\WC_Order $order): array
{
    $cgvUrl     = function_exists('luziapi_cgv_url') ? luziapi_cgv_url() : '';
    $cgvVersion = defined('LUZIAPI_CGV_VERSION') ? LUZIAPI_CGV_VERSION : '';

    if ($order instanceof \WC_Order) {
        $acceptedVersion = trim((string) $order->get_meta('_luziapi_cgv_version'));
        if ('' !== $acceptedVersion) {
            $cgvVersion = $acceptedVersion;
        }
    }

    return [
        'newsletter_url' => home_url('/#newsletter'),
        'site_url'       => home_url('/'),
        'logo_url'       => LUZIAPI_URI . '/assets/img/logo-email.png',
        'contact'        => luziapi_contact_details(),
        'cgv_url'        => $cgvUrl,
        'cgv_version'    => $cgvVersion,
        'withdrawal_url' => function_exists('luziapi_withdrawal_url') ? luziapi_withdrawal_url() : '',
        'mediation_url'  => '' !== $cgvUrl ? $cgvUrl . '#mediation' : '',
        'mediator_url'   => 'https://www.cm2c.net/',
    ];
}

/**
 * Prépare les textes propres à chaque e-mail WooCommerce encore natif.
 *
 * @param array{partial_refund?: bool, customer_note?: string} $context
 *
 * @return array{
 *     email_label: string,
 *     message_lines: list<string>,
 *     highlight_text: string,
 *     action_url: string,
 *     action_label: string,
 *     closing_line: string
 * }
 */
function luziapi_native_customer_email_presentation(
    \WC_Email $email,
    \WC_Order $order,
    array $context = []
): array {
    $orderNumber = $order->get_order_number();
    $defaults    = [
        'email_label'    => 'Votre commande',
        'message_lines'  => [sprintf('Voici les informations concernant votre commande n°%s.', $orderNumber)],
        'highlight_text' => '',
        'action_url'     => '',
        'action_label'   => '',
        'closing_line'   => 'Je reste disponible si vous avez une question.',
    ];

    switch ($email->id) {
        case 'customer_failed_order':
            return [
                'email_label'    => 'Paiement non abouti',
                'message_lines'  => [
                    sprintf('Votre commande n°%s n’a pas pu être finalisée en raison d’un problème avec son règlement.', $orderNumber),
                    'Vous pouvez consulter son récapitulatif ci-dessous et reprendre le règlement si nécessaire.',
                ],
                'highlight_text' => '',
                'action_url'     => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
                'action_label'   => $order->needs_payment() ? 'Reprendre mon règlement' : '',
                'closing_line'   => 'Je reste disponible si vous avez une question sur votre règlement.',
            ];

        case 'customer_refunded_order':
            $partialRefund = ! empty($context['partial_refund']);

            return [
                'email_label'    => $partialRefund ? 'Remboursement partiel' : 'Commande remboursée',
                'message_lines'  => [
                    $partialRefund
                        ? sprintf('Un remboursement partiel a été enregistré pour votre commande n°%s.', $orderNumber)
                        : sprintf('Le remboursement de votre commande n°%s a été enregistré.', $orderNumber),
                    'Vous trouverez le détail actualisé de la commande ci-dessous.',
                ],
                'highlight_text' => '',
                'action_url'     => '',
                'action_label'   => '',
                'closing_line'   => 'Je reste disponible si vous avez une question concernant ce remboursement.',
            ];

        case 'customer_note':
            return [
                'email_label'    => 'Nouveau message',
                'message_lines'  => [sprintf('Une information a été ajoutée à votre commande n°%s.', $orderNumber)],
                'highlight_text' => trim((string) ($context['customer_note'] ?? '')),
                'action_url'     => '',
                'action_label'   => '',
                'closing_line'   => 'Je reste disponible si vous avez besoin d’une précision.',
            ];

        case 'customer_invoice':
            $needsPayment = $order->needs_payment();

            return [
                'email_label'    => $needsPayment ? 'Règlement de la commande' : 'Détails de la commande',
                'message_lines'  => $needsPayment
                    ? [
                        sprintf('La commande n°%s a été préparée pour vous.', $orderNumber),
                        'Vous pouvez consulter son détail et choisir votre mode de règlement depuis le bouton ci-dessous.',
                    ]
                    : [sprintf('Voici le récapitulatif de votre commande n°%s.', $orderNumber)],
                'highlight_text' => '',
                'action_url'     => $needsPayment ? $order->get_checkout_payment_url() : '',
                'action_label'   => $needsPayment ? 'Régler ma commande' : '',
                'closing_line'   => 'Merci pour votre confiance et pour votre soutien à l’apiculture locale.',
            ];
    }

    return $defaults;
}

/**
 * Ajoute à la commande une trace privée, sans recopier le destinataire ni le
 * contenu du message. Un retour positif signifie que WordPress a remis le
 * message au transport local ; il ne garantit pas sa réception finale.
 *
 * @param mixed $email
 */
function luziapi_record_customer_email_delivery(bool $sent, string $emailId, $email): void
{
    if (! $email instanceof \WC_Email
        || ! $email->is_customer_email()
        || ! $email->object instanceof \WC_Order) {
        return;
    }

    $subject = trim(wp_strip_all_tags($email->get_subject()));
    if ('' === $subject) {
        $subject = '' !== trim($emailId) ? $emailId : 'Objet indisponible';
    }

    $deliveryStatus = luziapi_order_emails_disabled($email->object)
        ? 'non envoyé — désactivé pour cette commande'
        : ($sent ? 'transmis au service de messagerie' : 'non transmis — échec du transport');

    $email->object->add_order_note(
        sprintf(
            'E-mail client « %s » %s.',
            $subject,
            $deliveryStatus
        ),
        0
    );
}
add_action('woocommerce_email_sent', 'luziapi_record_customer_email_delivery', 10, 3);

/**
 * Bloque au dernier moment tous les e-mails WooCommerce associés à une
 * commande désactivée, y compris les notifications administrateur et les
 * envois manuels qui ne consultent pas toujours WC_Email::is_enabled().
 *
 * @param callable|string|mixed $callback
 * @param mixed                 $email
 *
 * @return callable|string|mixed
 */
function luziapi_maybe_disable_order_mail_callback($callback, $email)
{
    if ($email instanceof \WC_Email
        && $email->object instanceof \WC_Order
        && luziapi_order_emails_disabled($email->object)) {
        return '__return_false';
    }

    return $callback;
}
add_filter('woocommerce_mail_callback', 'luziapi_maybe_disable_order_mail_callback', 100, 2);
