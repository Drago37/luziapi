<?php

/**
 * CGV, preuve d'acceptation et exercice en ligne du droit de rétractation.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const LUZIAPI_CGV_VERSION = '2026-09-07-v2';
const LUZIAPI_CGV_PDF     = 'LuziApi-CGV-' . LUZIAPI_CGV_VERSION . '.pdf';

/**
 * Retourne l'URL publique d'une page légale, même avant sa création en base.
 */
function luziapi_legal_page_url(string $slug): string
{
    $page = get_page_by_path($slug);

    return $page instanceof \WP_Post
        ? (string) get_permalink($page)
        : home_url('/' . trim($slug, '/') . '/');
}

function luziapi_cgv_url(): string
{
    return luziapi_legal_page_url('conditions-generales-de-vente');
}

function luziapi_withdrawal_url(): string
{
    return luziapi_legal_page_url('retractation');
}

function luziapi_cgv_pdf_path(?\WC_Order $order = null): string
{
    $version = $order instanceof \WC_Order
        ? trim((string) $order->get_meta('_luziapi_cgv_version'))
        : '';

    if ('' === $version || 1 !== preg_match('/^[a-zA-Z0-9._-]+$/', $version)) {
        $version = LUZIAPI_CGV_VERSION;
    }

    return LUZIAPI_DIR . '/assets/docs/LuziApi-CGV-' . $version . '.pdf';
}

function luziapi_cgv_pdf_url(): string
{
    return LUZIAPI_URI . '/assets/docs/' . LUZIAPI_CGV_PDF;
}

// Le dernier geste du checkout doit annoncer sans ambiguïté l'obligation de payer.
add_filter('woocommerce_order_button_text', static fn (): string => 'Commander avec obligation de paiement');

// Formulation maîtrisée de la case native WooCommerce, qui reste décochée par défaut.
add_filter('woocommerce_get_terms_and_conditions_checkbox_text', static function (): string {
    return sprintf(
        'J’ai lu et j’accepte les <a href="%s" class="luziapi-terms-link" target="_blank" rel="noopener">Conditions générales de vente</a>.',
        esc_url(luziapi_cgv_url())
    );
});

// Information contractuelle liée au recueil, même facultatif, du numéro de téléphone.
add_filter('woocommerce_form_field', static function (
    string $field,
    string $key
): string {
    if ('billing_phone' !== $key || ! function_exists('is_checkout') || ! is_checkout()) {
        return $field;
    }

    return $field
        . '<p class="luziapi-phone-notice">Ce numéro sert uniquement au traitement de la commande et à l’organisation du rendez-vous. '
        . 'Aucun démarchage téléphonique sans votre consentement préalable.</p>';
}, 20, 2);

// Le contenu complet est long : le lien ouvre la page dédiée au lieu d'afficher
// l'extrait WordPress dans le petit panneau déroulant natif du checkout.
remove_action('woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30);

/**
 * Fige dans la commande la version des CGV acceptée par le client.
 */
add_action('woocommerce_checkout_create_order', static function (\WC_Order $order): void {
    if (empty($_POST['terms'])) {
        return;
    }

    $order->update_meta_data('_luziapi_cgv_version', LUZIAPI_CGV_VERSION);
    $order->update_meta_data('_luziapi_cgv_accepted_at', current_time('mysql', true));
}, 10, 1);

// Rend la preuve d'acceptation visible depuis l'administration de la commande.
add_action('woocommerce_admin_order_data_after_billing_address', static function (\WC_Order $order): void {
    $version = (string) $order->get_meta('_luziapi_cgv_version');
    $dateUtc = (string) $order->get_meta('_luziapi_cgv_accepted_at');

    if ('' === $version) {
        return;
    }

    $date = $dateUtc;
    if ('' !== $dateUtc) {
        $timestamp = strtotime($dateUtc . ' UTC');
        if (false !== $timestamp) {
            $date = wp_date('d/m/Y à H:i', $timestamp, new \DateTimeZone('Europe/Paris'));
        }
    }

    echo '<p><strong>CGV acceptées :</strong> version ' . esc_html($version);
    if ('' !== $date) {
        echo ', le ' . esc_html($date);
    }
    echo '.</p>';
});

/**
 * Joint la copie durable des CGV au premier e-mail confirmant une commande.
 * Le marqueur est posé uniquement après un envoi réussi par la classe d'e-mail.
 *
 * @param list<string>          $attachments
 * @param mixed                 $object
 * @param \WC_Email|mixed|null $email
 *
 * @return list<string>
 */
add_filter('woocommerce_email_attachments', static function (
    array $attachments,
    string $emailId,
    $object,
    $email = null
): array {
    if (! $object instanceof \WC_Order) {
        return $attachments;
    }

    if (! in_array($emailId, ['luziapi_customer_on_hold', 'luziapi_customer_processing'], true)) {
        return $attachments;
    }

    if ('yes' === $object->get_meta('_luziapi_cgv_copy_sent')) {
        return $attachments;
    }

    $path = luziapi_cgv_pdf_path($object);
    if (is_readable($path)) {
        $attachments[] = $path;
    }

    return array_values(array_unique($attachments));
}, 10, 4);

/**
 * Mémorise que la copie durable a réellement accompagné un e-mail client.
 */
function luziapi_mark_cgv_copy_sent(\WC_Order $order): void
{
    if (! is_readable(luziapi_cgv_pdf_path($order))) {
        return;
    }

    $order->update_meta_data('_luziapi_cgv_copy_sent', 'yes');
    $order->save();
}

/**
 * Ajoute les liens légaux aux détails d'une commande consultée par le client.
 */
add_action('woocommerce_order_details_after_order_table', static function (): void {
    printf(
        '<div class="luziapi-order-legal"><p><a href="%s">Consulter les CGV</a> · <a href="%s">Renoncer au contrat ici</a></p></div>',
        esc_url(luziapi_cgv_url()),
        esc_url(luziapi_withdrawal_url())
    );
});

/**
 * @return array{order_number: string, email: string, scope: string, details: string}
 */
function luziapi_withdrawal_values(): array
{
    return [
        'order_number' => isset($_POST['order_number'])
            ? sanitize_text_field(wp_unslash((string) $_POST['order_number']))
            : '',
        'email' => isset($_POST['email'])
            ? sanitize_email(wp_unslash((string) $_POST['email']))
            : '',
        'scope' => isset($_POST['scope']) && 'part' === $_POST['scope'] ? 'part' : 'all',
        'details' => isset($_POST['details'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['details']))
            : '',
    ];
}

/**
 * Vérifie que la demande désigne bien une commande et son acheteur sans exposer
 * d'information sur une commande à un tiers.
 *
 * @param array{order_number: string, email: string, scope: string, details: string} $values
 *
 * @return array{order: \WC_Order|null, errors: list<string>}
 */
function luziapi_validate_withdrawal(array $values): array
{
    $errors = [];

    if ('' === $values['order_number'] || '' === $values['email']) {
        $errors[] = 'Renseignez le numéro de commande et l’adresse e-mail utilisée lors de l’achat.';
    }

    if ('' !== $values['email'] && ! is_email($values['email'])) {
        $errors[] = 'L’adresse e-mail renseignée n’est pas valide.';
    }

    if ('part' === $values['scope'] && '' === $values['details']) {
        $errors[] = 'Précisez les produits concernés par votre demande.';
    }

    $order = null;
    if ([] === $errors && ctype_digit($values['order_number'])) {
        $candidate = wc_get_order((int) $values['order_number']);
        if ($candidate instanceof \WC_Order
            && hash_equals(
                strtolower(trim($candidate->get_billing_email())),
                strtolower(trim($values['email']))
            )) {
            $order = $candidate;
        }
    }

    if ([] === $errors && ! $order instanceof \WC_Order) {
        $errors[] = 'Les informations fournies ne permettent pas d’identifier la commande. Vérifiez le numéro et l’adresse e-mail.';
    }

    return ['order' => $order, 'errors' => $errors];
}

/**
 * Enregistre la rétractation, prévient LuziApi et accuse réception au client.
 * Aucun statut, paiement ni stock n'est modifié automatiquement.
 *
 * @param array{order_number: string, email: string, scope: string, details: string} $values
 *
 * @return array{reference: string, submitted_at: string, customer_email_sent: bool}
 */
function luziapi_record_withdrawal(\WC_Order $order, array $values): array
{
    $reference   = strtoupper(wp_generate_password(10, false, false));
    $timezone    = new \DateTimeZone('Europe/Paris');
    $now         = new \DateTimeImmutable('now', $timezone);
    $submittedAt = wp_date('d/m/Y à H:i', $now->getTimestamp(), $timezone);
    $scope       = 'part' === $values['scope']
        ? 'Une partie de la commande : ' . $values['details']
        : 'La totalité de la commande';

    $request = [
        'reference'    => $reference,
        'submitted_at' => $now->format(DATE_ATOM),
        'scope'        => $values['scope'],
        'details'      => $values['details'],
    ];

    $order->add_order_note(
        sprintf(
            'Demande de rétractation %s reçue le %s. Portée : %s. Aucun statut modifié automatiquement.',
            $reference,
            $submittedAt,
            $scope
        ),
        0
    );
    $order->add_meta_data('_luziapi_withdrawal_request', wp_json_encode($request), false);
    $order->save();

    $customerMessage = implode("\n", [
        'Bonjour' . ($order->get_billing_first_name() ? ' ' . $order->get_billing_first_name() : '') . ',',
        '',
        'Nous accusons réception de votre demande de rétractation.',
        'Référence de la demande : ' . $reference,
        'Commande : n°' . $order->get_order_number(),
        'Date et heure : ' . $submittedAt . ' (heure de Paris)',
        'Produits concernés : ' . $scope,
        '',
        'LuziApi prendra contact avec vous pour confirmer la recevabilité de la demande et, le cas échéant, organiser le retour des pots intacts, non ouverts et toujours scellés.',
        'Aucun retour ne doit être effectué avant cet échange.',
        '',
        'LuziApi — luziapi37150@gmail.com',
    ]);

    $customerSent = wp_mail(
        $order->get_billing_email(),
        sprintf('Rétractation reçue — commande LuziApi n°%s', $order->get_order_number()),
        $customerMessage
    );

    $adminMessage = implode("\n", [
        'Une demande de rétractation a été enregistrée.',
        'Référence : ' . $reference,
        'Commande : n°' . $order->get_order_number(),
        'Client : ' . trim($order->get_formatted_billing_full_name()),
        'E-mail : ' . $order->get_billing_email(),
        'Date et heure : ' . $submittedAt,
        'Produits concernés : ' . $scope,
        '',
        'Aucun statut, remboursement ou mouvement de stock n’a été déclenché automatiquement.',
    ]);

    wp_mail(
        'luziapi37150@gmail.com',
        sprintf('Demande de rétractation %s — commande n°%s', $reference, $order->get_order_number()),
        $adminMessage
    );

    return [
        'reference'           => $reference,
        'submitted_at'        => $submittedAt,
        'customer_email_sent' => $customerSent,
    ];
}

/**
 * Prépare les trois états de la page : formulaire, récapitulatif, confirmation.
 *
 * @return array<string, mixed>
 */
function luziapi_withdrawal_page_context(): array
{
    $context = [
        'step'   => 'form',
        'values' => [
            'order_number' => '',
            'email'        => '',
            'scope'        => 'all',
            'details'      => '',
        ],
        'errors' => [],
        'result' => null,
    ];

    if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
        return $context;
    }

    $values          = luziapi_withdrawal_values();
    $context['values'] = $values;
    $nonce           = isset($_POST['luziapi_withdrawal_nonce'])
        ? sanitize_text_field(wp_unslash((string) $_POST['luziapi_withdrawal_nonce']))
        : '';

    if (! wp_verify_nonce($nonce, 'luziapi_withdrawal')) {
        $context['errors'] = ['La session a expiré. Rechargez la page et recommencez.'];

        return $context;
    }

    if (! empty($_POST['website'])) {
        $context['errors'] = ['La demande n’a pas pu être enregistrée.'];

        return $context;
    }

    $validation        = luziapi_validate_withdrawal($values);
    $context['errors'] = $validation['errors'];

    if ([] !== $validation['errors'] || ! $validation['order'] instanceof \WC_Order) {
        return $context;
    }

    $action = isset($_POST['withdrawal_action'])
        ? sanitize_key(wp_unslash((string) $_POST['withdrawal_action']))
        : 'review';

    if ('confirm' === $action) {
        $context['step']   = 'success';
        $context['result'] = luziapi_record_withdrawal($validation['order'], $values);

        return $context;
    }

    $context['step'] = 'review';

    return $context;
}
