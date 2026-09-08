<?php

/**
 * Livraison locale, retrait sur rendez-vous et cycle métier des commandes.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const LUZIAPI_ORDER_SOURCE_META = '_luziapi_order_source';
const LUZIAPI_ORDER_EMAILS_DISABLED_META = '_luziapi_order_emails_disabled';
const LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META = '_wc_order_attribution_source_type';

/**
 * Normalise une ville saisie librement pour comparer Bléré/Luzillé sans tenir
 * compte des accents, des espaces ou de la casse.
 */
function luziapi_normalize_city(string $city): string
{
    $city = mb_strtolower(remove_accents(sanitize_text_field($city)));

    return (string) preg_replace('/[^a-z]/', '', $city);
}

/**
 * La livraison gratuite est strictement réservée à Bléré et Luzillé.
 * Le code postal seul ne suffit pas car plusieurs communes utilisent 37150.
 *
 * @param array<string, mixed> $destination
 */
function luziapi_is_local_delivery_destination(array $destination): bool
{
    $country  = strtoupper((string) ($destination['country'] ?? ''));
    $postcode = preg_replace('/\s+/', '', (string) ($destination['postcode'] ?? ''));
    $city     = luziapi_normalize_city((string) ($destination['city'] ?? ''));

    return 'FR' === $country
        && '37150' === $postcode
        && in_array($city, ['blere', 'luzille'], true);
}

/**
 * Retourne le mode de remise réellement enregistré dans la commande.
 */
function luziapi_order_fulfillment_mode(\WC_Order $order): string
{
    foreach ($order->get_shipping_methods() as $shippingItem) {
        if ('free_shipping' === $shippingItem->get_method_id()) {
            return 'delivery';
        }

        if ('local_pickup' === $shippingItem->get_method_id()) {
            return 'pickup';
        }
    }

    return 'unknown';
}

function luziapi_order_status_matches_fulfillment(\WC_Order $order, string $status): bool
{
    $mode = luziapi_order_fulfillment_mode($order);

    if ('out_for_delivery' === $status) {
        return 'pickup' !== $mode;
    }

    if ('ready_for_pickup' === $status) {
        return 'delivery' !== $mode;
    }

    return true;
}

/**
 * @return array<string, string>
 */
function luziapi_order_source_options(): array
{
    return [
        'online'     => 'Boutique en ligne',
        'phone'      => 'Téléphone',
        'market'     => 'Marché / événement',
        'email_form' => 'E-mail / formulaire',
        'social'     => 'Réseaux sociaux',
        'other'      => 'Autre',
    ];
}

/**
 * Retourne la source LuziApi enregistrée. Les anciennes commandes issues du
 * checkout sont reconnues sans nécessiter de migration en base.
 */
function luziapi_order_source(\WC_Order $order): string
{
    $source = trim((string) $order->get_meta(LUZIAPI_ORDER_SOURCE_META));
    if (isset(luziapi_order_source_options()[$source])) {
        return $source;
    }

    return in_array($order->get_created_via(), ['checkout', 'store-api'], true) ? 'online' : '';
}

function luziapi_order_emails_disabled(\WC_Order $order): bool
{
    return 'yes' === $order->get_meta(LUZIAPI_ORDER_EMAILS_DISABLED_META);
}

function luziapi_order_fulfillment_is_locked(\WC_Order $order): bool
{
    return $order->has_status(['out-for-delivery', 'ready-for-pickup', 'completed', 'cancelled', 'refunded']);
}

/**
 * Une commande saisie dans WooCommerce doit être reconnue comme telle par
 * l'attribution native, sans transformer sa source commerciale en campagne.
 * Les commandes du checkout restent non attribuées si leur navigateur n'a
 * transmis aucune donnée marketing.
 */
function luziapi_maybe_set_admin_order_attribution(\WC_Order $order): bool
{
    if ('' !== trim((string) $order->get_meta(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META))) {
        return false;
    }

    $source = luziapi_order_source($order);
    if ('online' === $source || ('' === $source && ! in_array($order->get_created_via(), ['admin', 'edit-order'], true))) {
        return false;
    }

    $order->update_meta_data(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META, 'admin');
    $order->save_meta_data();

    return true;
}

/**
 * Remplace le peu clair « Inconnue » de WooCommerce sans inventer une source
 * publicitaire qui n'a pas été collectée.
 *
 * @param mixed $formattedSource
 * @param mixed $source
 */
function luziapi_format_unknown_order_attribution($formattedSource, $source): string
{
    if (trim((string) $source) === __('Unknown', 'woocommerce')) {
        return 'Attribution marketing indisponible';
    }

    if (trim((string) $source) === __('Web admin', 'woocommerce')) {
        return 'Administration web';
    }

    return (string) $formattedSource;
}
add_filter(
    'wc_order_attribution_origin_formatted_source',
    'luziapi_format_unknown_order_attribution',
    20,
    2
);

/**
 * CookieAdmin stocke soit un consentement global, soit les catégories
 * autorisées dans un cookie JSON.
 */
function luziapi_cookieadmin_allows_order_attribution(string $cookie): bool
{
    if ('' === trim($cookie)) {
        return false;
    }

    $consent = json_decode(rawurldecode($cookie), true);
    if (! is_array($consent)) {
        return false;
    }

    $isAllowed = static function ($value): bool {
        return true === $value || 1 === $value || in_array(strtolower((string) $value), ['true', '1'], true);
    };

    return $isAllowed($consent['accept'] ?? false)
        || $isAllowed($consent['marketing'] ?? false);
}

function luziapi_cookieadmin_is_active(): bool
{
    $plugin = 'cookieadmin/cookieadmin.php';
    if (in_array($plugin, (array) get_option('active_plugins', []), true)) {
        return true;
    }

    return is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', []))[$plugin]);
}

/**
 * L'attribution WooCommerce utilise des cookies marketing. En présence de
 * CookieAdmin, elle ne démarre qu'après le consentement correspondant.
 *
 * @param mixed $allowTracking
 */
function luziapi_filter_order_attribution_consent($allowTracking): bool
{
    if (! $allowTracking || ! luziapi_cookieadmin_is_active()) {
        return (bool) $allowTracking;
    }

    $cookie = isset($_COOKIE['cookieadmin_consent'])
        ? wp_unslash((string) $_COOKIE['cookieadmin_consent'])
        : '';

    return luziapi_cookieadmin_allows_order_attribution($cookie);
}
add_filter('wc_order_attribution_allow_tracking', 'luziapi_filter_order_attribution_consent', 30);

/**
 * CookieAdmin ne publie pas l'événement standard WP Consent API. Ce pont
 * répercute donc immédiatement un choix fait dans la bannière vers le moteur
 * d'attribution déjà chargé par WooCommerce.
 */
function luziapi_add_cookieadmin_order_attribution_bridge(): void
{
    if (! luziapi_cookieadmin_is_active() || ! wp_script_is('wc-order-attribution', 'enqueued')) {
        return;
    }

    wp_add_inline_script(
        'wc-order-attribution',
        <<<'JS'
            document.addEventListener('click', function (event) {
                const button = event.target.closest('.cookieadmin_accept_btn, .cookieadmin_reject_btn, .cookieadmin_save_btn');
                if (!button) return;

                window.setTimeout(function () {
                    if (!window.wc_order_attribution || typeof window.wc_order_attribution.setOrderTracking !== 'function') return;

                    const action = window.cookieadmin_is_consent && window.cookieadmin_is_consent.action
                        ? window.cookieadmin_is_consent.action
                        : {};
                    const allowed = action.accept === true || action.accept === 'true'
                        || action.marketing === true || action.marketing === 'true';
                    window.wc_order_attribution.setOrderTracking(allowed);
                }, 0);
            });
            JS
    );
}
add_action('wp_enqueue_scripts', 'luziapi_add_cookieadmin_order_attribution_bridge', 30);

/**
 * Les commandes passées sur le site sont identifiées indépendamment de
 * l'attribution marketing WooCommerce (accès direct, moteur, campagne…).
 *
 * @param array<string, mixed> $data
 */
add_action('woocommerce_checkout_create_order', static function (\WC_Order $order, array $data): void {
    if ('' === trim((string) $order->get_meta(LUZIAPI_ORDER_SOURCE_META))) {
        $order->update_meta_data(LUZIAPI_ORDER_SOURCE_META, 'online');
    }
}, 20, 2);

/**
 * Affiche les deux modes convenus et masque réellement la livraison pour toute
 * autre destination. Le retrait reste disponible quelle que soit la commune.
 *
 * @param array<string, \WC_Shipping_Rate> $rates
 * @param array<string, mixed>              $package
 *
 * @return array<string, \WC_Shipping_Rate>
 */
add_filter('woocommerce_package_rates', static function (array $rates, array $package): array {
    $destination         = is_array($package['destination'] ?? null) ? $package['destination'] : [];
    $isLocalDestination  = luziapi_is_local_delivery_destination($destination);

    foreach ($rates as $rateId => $rate) {
        if ('free_shipping' === $rate->get_method_id()) {
            if (! $isLocalDestination) {
                unset($rates[$rateId]);
                continue;
            }

            $rate->set_label('Livraison gratuite sur Luzillé ou Bléré sur RDV');
        }

        if ('local_pickup' === $rate->get_method_id()) {
            $rate->set_label('Retrait à mon domicile à Luzillé sur RDV');
        }
    }

    return $rates;
}, 20, 2);

/**
 * Garde-fou serveur si un ancien tarif de livraison est resté sélectionné dans
 * la session après une modification de l'adresse.
 *
 * @param array<string, mixed> $data
 */
add_action('woocommerce_after_checkout_validation', static function (array $data, \WP_Error $errors): void {
    if (! function_exists('WC') || ! WC()->session) {
        return;
    }

    $chosenMethods = WC()->session->get('chosen_shipping_methods', []);
    if (! is_array($chosenMethods)) {
        return;
    }

    $usesFreeDelivery = (bool) array_filter(
        $chosenMethods,
        static fn ($method): bool => 'free_shipping' === strtok((string) $method, ':')
    );

    if (! $usesFreeDelivery) {
        return;
    }

    $prefix = ! empty($data['ship_to_different_address']) ? 'shipping' : 'billing';
    $destination = [
        'country'  => $data[$prefix . '_country'] ?? '',
        'postcode' => $data[$prefix . '_postcode'] ?? '',
        'city'     => $data[$prefix . '_city'] ?? '',
    ];

    if (! luziapi_is_local_delivery_destination($destination)) {
        $errors->add(
            'luziapi_delivery_city',
            'La livraison gratuite est réservée aux adresses situées à Bléré ou Luzillé (37150). Choisissez le retrait à Luzillé ou corrigez l’adresse de livraison.'
        );
    }
}, 10, 2);

// Deux étapes manuelles après la préparation, selon le mode choisi au checkout.
add_action('init', static function (): void {
    register_post_status('wc-out-for-delivery', [
        'label'                     => 'En cours de livraison',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'En cours de livraison <span class="count">(%s)</span>',
            'En cours de livraison <span class="count">(%s)</span>'
        ),
    ]);

    register_post_status('wc-ready-for-pickup', [
        'label'                     => 'Prête au retrait',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Prête au retrait <span class="count">(%s)</span>',
            'Prêtes au retrait <span class="count">(%s)</span>'
        ),
    ]);
}, 5);

add_filter('wc_order_statuses', static function (array $statuses): array {
    $orderedStatuses = [];

    foreach ($statuses as $status => $label) {
        $orderedStatuses[$status] = $label;

        if ('wc-processing' === $status) {
            $orderedStatuses['wc-out-for-delivery'] = 'En cours de livraison';
            $orderedStatuses['wc-ready-for-pickup'] = 'Prête au retrait';
        }
    }

    return $orderedStatuses;
});

// Ces deux étapes suivent la confirmation de commande : elles restent payées
// au sens WooCommerce et conservent le stock déjà décrémenté.
add_filter('woocommerce_order_is_paid_statuses', static function (array $statuses): array {
    $statuses[] = 'out-for-delivery';
    $statuses[] = 'ready-for-pickup';

    return array_values(array_unique($statuses));
});

add_action('woocommerce_order_status_out-for-delivery', 'wc_maybe_reduce_stock_levels');
add_action('woocommerce_order_status_ready-for-pickup', 'wc_maybe_reduce_stock_levels');

// Les statuts personnalisés doivent passer par le répartiteur transactionnel
// de WooCommerce pour profiter de la file différée et de sa gestion d'erreurs.
add_filter('woocommerce_email_actions', static function (array $actions): array {
    $actions[] = 'woocommerce_order_status_out-for-delivery';
    $actions[] = 'woocommerce_order_status_ready-for-pickup';

    return array_values(array_unique($actions));
});

/**
 * Remplace les trois messages clients génériques de WooCommerce et ajoute les
 * deux messages métier LuziApi. Les e-mails administrateur restent inchangés.
 *
 * @param array<string, \WC_Email> $emails
 *
 * @return array<string, \WC_Email>
 */
add_filter('woocommerce_email_classes', static function (array $emails): array {
    require_once LUZIAPI_DIR . '/inc/class-luziapi-order-status-email.php';

    $replacedEmails = [
        'WC_Email_Customer_On_Hold_Order' => [
            'woocommerce_order_status_pending_to_on-hold_notification',
            'woocommerce_order_status_failed_to_on-hold_notification',
            'woocommerce_order_status_cancelled_to_on-hold_notification',
        ],
        'WC_Email_Customer_Processing_Order' => [
            'woocommerce_order_status_pending_to_processing_notification',
            'woocommerce_order_status_failed_to_processing_notification',
            'woocommerce_order_status_on-hold_to_processing_notification',
            'woocommerce_order_status_cancelled_to_processing_notification',
        ],
        'WC_Email_Customer_Completed_Order' => [
            'woocommerce_order_status_completed_notification',
        ],
        'WC_Email_Customer_Cancelled_Order' => [
            'woocommerce_order_status_processing_to_cancelled_notification',
            'woocommerce_order_status_on-hold_to_cancelled_notification',
        ],
    ];

    foreach ($replacedEmails as $className => $hooks) {
        if (! isset($emails[$className])) {
            continue;
        }

        foreach ($hooks as $hook) {
            remove_action($hook, [$emails[$className], 'trigger'], 10);
        }

        unset($emails[$className]);
    }

    $definitions = [
        'Luziapi_Email_Customer_On_Hold' => [
            'id'          => 'luziapi_customer_on_hold',
            'title'       => 'LuziApi — Commande en attente',
            'description' => 'Confirme la réception de la commande en attente du règlement.',
            'subject'     => 'Commande LuziApi n°{order_number} reçue — règlement en attente',
            'heading'     => 'Commande reçue',
            'message'     => 'on_hold',
            'hooks'       => $replacedEmails['WC_Email_Customer_On_Hold_Order'],
        ],
        'Luziapi_Email_Customer_Processing' => [
            'id'          => 'luziapi_customer_processing',
            'title'       => 'LuziApi — Commande confirmée',
            'description' => 'Informe le client que sa commande est confirmée et va être préparée.',
            'subject'     => 'Votre commande LuziApi n°{order_number} est confirmée',
            'heading'     => 'Votre commande est confirmée',
            'message'     => 'processing',
            'hooks'       => $replacedEmails['WC_Email_Customer_Processing_Order'],
        ],
        'Luziapi_Email_Customer_Out_For_Delivery' => [
            'id'          => 'luziapi_customer_out_for_delivery',
            'title'       => 'LuziApi — En cours de livraison',
            'description' => 'Invite le client à convenir du jour et de l’heure de la livraison.',
            'subject'     => 'Organisons la livraison de votre commande LuziApi n°{order_number}',
            'heading'     => 'Votre commande est prête à être livrée',
            'message'     => 'out_for_delivery',
            'hooks'       => ['woocommerce_order_status_out-for-delivery_notification'],
        ],
        'Luziapi_Email_Customer_Ready_For_Pickup' => [
            'id'          => 'luziapi_customer_ready_for_pickup',
            'title'       => 'LuziApi — Prête au retrait',
            'description' => 'Indique l’adresse fixe et invite le client à convenir du jour et de l’heure du retrait.',
            'subject'     => 'Votre commande LuziApi n°{order_number} est prête au retrait',
            'heading'     => 'Votre commande est prête au retrait',
            'message'     => 'ready_for_pickup',
            'hooks'       => ['woocommerce_order_status_ready-for-pickup_notification'],
        ],
        'Luziapi_Email_Customer_Completed' => [
            'id'          => 'luziapi_customer_completed',
            'title'       => 'LuziApi — Commande terminée',
            'description' => 'Confirme la remise et propose l’inscription aux actualités par e-mail ou SMS.',
            'subject'     => 'Votre commande LuziApi n°{order_number} a bien été remise',
            'heading'     => 'Merci pour votre commande',
            'message'     => 'completed',
            'hooks'       => $replacedEmails['WC_Email_Customer_Completed_Order'],
        ],
        'Luziapi_Email_Customer_Payment_Reminder' => [
            'id'          => 'luziapi_customer_payment_reminder',
            'title'       => 'LuziApi — Rappel de règlement',
            'description' => 'Rappelle l’échéance d’une commande en attente de virement ou WERO.',
            'subject'     => 'Rappel — règlement de votre commande LuziApi n°{order_number}',
            'heading'     => 'Votre règlement est toujours en attente',
            'message'     => 'payment_reminder',
            'hooks'       => ['luziapi_bacs_payment_reminder_notification'],
        ],
        'Luziapi_Email_Customer_Cancelled' => [
            'id'          => 'luziapi_customer_cancelled',
            'title'       => 'LuziApi — Commande annulée',
            'description' => 'Informe le client uniquement lorsqu’un motif d’annulation est renseigné.',
            'subject'     => 'Votre commande LuziApi n°{order_number} a été annulée',
            'heading'     => 'Votre commande a été annulée',
            'message'     => 'cancelled',
            'hooks'       => $replacedEmails['WC_Email_Customer_Cancelled_Order'],
        ],
    ];

    foreach ($definitions as $className => $definition) {
        $emails[$className] = new \Luziapi_Order_Status_Email($definition);
    }

    return $emails;
});

/**
 * Dans l'administration, rappelle le mode de remise et exige un motif avant
 * toute annulation. Le contrôle côté e-mail reste actif pour les changements
 * provenant d'une API ou d'une action groupée.
 */
add_action('woocommerce_admin_order_data_after_order_details', static function (\WC_Order $order): void {
    $mode           = luziapi_order_fulfillment_mode($order);
    $source         = luziapi_order_source($order);
    $reason         = (string) $order->get_meta('_luziapi_cancellation_reason');
    $emailsDisabled = luziapi_order_emails_disabled($order);
    $modeLocked     = luziapi_order_fulfillment_is_locked($order);

    wp_nonce_field('luziapi_save_order_workflow', 'luziapi_order_workflow_nonce');
    ?>
    <div class="luziapi-order-workflow" data-fulfillment="<?php echo esc_attr($mode); ?>" data-emails-disabled="<?php echo $emailsDisabled ? 'yes' : 'no'; ?>" style="clear:both;padding-top:12px;">
        <p class="form-field form-field-wide">
            <label for="luziapi_order_source"><strong>Source commande</strong></label>
            <select id="luziapi_order_source" name="luziapi_order_source" style="width:100%;">
                <option value="">À renseigner</option>
                <?php foreach (luziapi_order_source_options() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($source, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="description">Source par laquelle la commande a été reçue. Indépendante de l’origine marketing affichée par WooCommerce.</span>
        </p>
        <p class="form-field form-field-wide">
            <label for="luziapi_fulfillment_mode"><strong>Mode de remise</strong></label>
            <select id="luziapi_fulfillment_mode" name="luziapi_fulfillment_mode" style="width:100%;" <?php disabled($modeLocked); ?>>
                <option value="">À renseigner</option>
                <option value="delivery" <?php selected($mode, 'delivery'); ?>>Livraison gratuite à Luzillé ou Bléré sur rendez-vous</option>
                <option value="pickup" <?php selected($mode, 'pickup'); ?>>Retrait au domicile de LuziApi à Luzillé sur rendez-vous</option>
            </select>
            <?php if ($modeLocked) : ?>
                <span class="description">Le mode est verrouillé à partir de l’étape de remise pour éviter un statut ou un e-mail incohérent.</span>
            <?php else : ?>
                <span class="description">La livraison est acceptée uniquement pour une adresse à Luzillé ou Bléré, 37150, France.</span>
            <?php endif; ?>
        </p>
        <p class="form-field form-field-wide">
            <label for="luziapi_disable_order_emails" style="display:flex;gap:7px;align-items:flex-start;">
                <input type="checkbox" id="luziapi_disable_order_emails" name="luziapi_disable_order_emails" value="yes" <?php checked($emailsDisabled); ?>>
                <strong>Ne pas envoyer d’e-mails pour cette commande</strong>
            </label>
            <span class="description">Les statuts et le stock continueront d’évoluer, mais aucun e-mail WooCommerce ne sera envoyé au client ni à LuziApi.</span>
        </p>
        <p class="form-field form-field-wide">
            <label for="luziapi_cancellation_reason"><strong>Motif d’annulation communiqué au client</strong></label>
            <textarea id="luziapi_cancellation_reason" name="luziapi_cancellation_reason" rows="3" style="width:100%;"><?php echo esc_textarea($reason); ?></textarea>
            <span class="description">Obligatoire avant de sélectionner « Annulée ». Ce texte sera repris dans l’e-mail client et tracé dans la commande.</span>
        </p>
    </div>
    <style>
        #order_data .order_data_column .form-field .luziapi-order-datetime {
            display: grid;
            grid-template-columns: 132px 76px;
            gap: 8px;
            align-items: center;
            width: 100%;
        }
        #order_data .order_data_column .form-field .luziapi-order-datetime input {
            box-sizing: border-box;
            float: none;
            margin: 0;
        }
        #order_data .order_data_column .form-field #luziapi_order_date_display {
            width: 132px;
        }
        #order_data .order_data_column .form-field #luziapi_order_time_display {
            width: 76px;
        }
        #order_data .order_data_column .form-field #luziapi-order-datetime-help {
            grid-column: 1 / -1;
            margin-top: 0;
        }
        #woocommerce-order-notes .luziapi-note-kind {
            display: inline-block;
            margin: 0 0 5px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #ece7df;
            color: #4b352d;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
        }
        #woocommerce-order-notes li.customer-note .luziapi-note-kind {
            background: #fff0bd;
            color: #704d00;
        }
        #luziapi-order-note-help[data-type="customer"] {
            color: #8a5a00;
            font-weight: 600;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const box = document.querySelector('.luziapi-order-workflow');
        const status = document.querySelector('#order_status');
        const reason = document.querySelector('#luziapi_cancellation_reason');
        const fulfillment = document.querySelector('#luziapi_fulfillment_mode');
        const emailsDisabled = document.querySelector('#luziapi_disable_order_emails');

        const nativeDate = document.querySelector('input[name="order_date"]');
        const nativeHour = document.querySelector('input[name="order_date_hour"]');
        const nativeMinute = document.querySelector('input[name="order_date_minute"]');
        if (nativeDate && nativeHour && nativeMinute) {
            const dateField = nativeDate.closest('p.form-field');
            const dateLabel = dateField ? dateField.querySelector('label') : null;

            if (dateField && dateLabel) {
                const dateParts = nativeDate.value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                const displayDate = dateParts
                    ? dateParts[3] + '/' + dateParts[2] + '/' + dateParts[1]
                    : '';
                const displayTime = String(nativeHour.value).padStart(2, '0')
                    + ':' + String(nativeMinute.value).padStart(2, '0');

                Array.from(dateField.childNodes).forEach(function (node) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        node.textContent = '';
                    } else if (node.matches && node.matches('input')) {
                        node.style.display = 'none';
                    }
                });

                dateLabel.textContent = 'Date et heure de la commande';
                dateLabel.htmlFor = 'luziapi_order_date_display';

                const fields = document.createElement('span');
                fields.className = 'luziapi-order-datetime';
                fields.innerHTML = '<input type="text" id="luziapi_order_date_display" value="' + displayDate
                    + '" maxlength="10" inputmode="numeric" autocomplete="off" placeholder="JJ/MM/AAAA" aria-label="Date de la commande au format jour, mois, année" aria-describedby="luziapi-order-datetime-help">'
                    + '<input type="text" id="luziapi_order_time_display" value="' + displayTime
                    + '" maxlength="5" inputmode="numeric" autocomplete="off" placeholder="HH:MM" aria-label="Heure de la commande au format 24 heures" aria-describedby="luziapi-order-datetime-help">'
                    + '<span class="description" id="luziapi-order-datetime-help">Jour / mois / année · heure sur 24 h</span>';
                dateField.appendChild(fields);

                const visibleDate = fields.querySelector('#luziapi_order_date_display');
                const visibleTime = fields.querySelector('#luziapi_order_time_display');

                const syncDate = function () {
                    const parts = visibleDate.value.trim().match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                    if (!parts) return false;

                    const day = Number(parts[1]);
                    const month = Number(parts[2]);
                    const year = Number(parts[3]);
                    const date = new Date(Date.UTC(year, month - 1, day));
                    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
                        return false;
                    }

                    nativeDate.value = parts[3] + '-' + parts[2] + '-' + parts[1];
                    visibleDate.value = parts[1] + '/' + parts[2] + '/' + parts[3];
                    return true;
                };

                const syncTime = function () {
                    const parts = visibleTime.value.trim().match(/^([01]\d|2[0-3]):([0-5]\d)$/);
                    if (!parts) return false;

                    nativeHour.value = parts[1];
                    nativeMinute.value = parts[2];
                    visibleTime.value = parts[1] + ':' + parts[2];
                    return true;
                };

                visibleDate.addEventListener('change', syncDate);
                visibleTime.addEventListener('change', syncTime);

                const orderForm = dateField.closest('form');
                if (orderForm) {
                    orderForm.addEventListener('submit', function (event) {
                        if (!syncDate()) {
                            event.preventDefault();
                            window.alert('Saisissez une date valide au format JJ/MM/AAAA.');
                            visibleDate.focus();
                            return;
                        }
                        if (!syncTime()) {
                            event.preventDefault();
                            window.alert('Saisissez une heure valide au format HH:MM (sur 24 heures).');
                            visibleTime.focus();
                        }
                    });
                }
            }
        }

        const noteType = document.querySelector('#order_note_type');
        const notesList = document.querySelector('#woocommerce-order-notes .order_notes');
        if (noteType) {
            const privateOption = noteType.querySelector('option[value=""]');
            const customerOption = noteType.querySelector('option[value="customer"]');
            if (privateOption) privateOption.textContent = 'Note privée — administration uniquement';
            if (customerOption) customerOption.textContent = 'Note au client — envoyée par e-mail';

            const noteActions = noteType.closest('p');
            const help = document.createElement('span');
            help.id = 'luziapi-order-note-help';
            help.className = 'description';
            help.style.display = 'block';
            help.style.marginTop = '8px';
            if (noteActions) noteActions.appendChild(help);

            const updateNoteHelp = function () {
                const isCustomerNote = noteType.value === 'customer';
                help.dataset.type = isCustomerNote ? 'customer' : 'private';
                if (!isCustomerNote) {
                    help.textContent = 'Visible uniquement dans l’administration. Aucun e-mail n’est envoyé.';
                    return;
                }

                const suppressionSaved = box && box.dataset.emailsDisabled === 'yes';
                const suppressionSelected = emailsDisabled && emailsDisabled.checked;
                if (suppressionSaved) {
                    help.textContent = 'Note destinée au client, mais son e-mail sera bloqué car les e-mails de cette commande sont désactivés.';
                } else if (suppressionSelected) {
                    help.textContent = 'Enregistrez d’abord la commande pour désactiver les e-mails avant d’ajouter cette note.';
                } else {
                    help.textContent = 'Cette note déclenche immédiatement un e-mail au client.';
                }
            };
            noteType.addEventListener('change', updateNoteHelp);
            if (emailsDisabled) emailsDisabled.addEventListener('change', updateNoteHelp);
            updateNoteHelp();

            const addNoteButton = document.querySelector('#woocommerce-order-notes button.add_note');
            if (addNoteButton) {
                addNoteButton.addEventListener('click', function (event) {
                    if (noteType.value !== 'customer') return;

                    const suppressionSaved = box && box.dataset.emailsDisabled === 'yes';
                    const suppressionSelected = emailsDisabled && emailsDisabled.checked;
                    if (suppressionSaved !== suppressionSelected) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('Le réglage d’envoi des e-mails a changé. Enregistrez d’abord la commande, puis ajoutez la note au client.');
                        return;
                    }

                    const message = suppressionSaved
                        ? 'Ajouter cette note au client ? Aucun e-mail ne sera envoyé car ils sont désactivés pour cette commande.'
                        : 'Cette note va être envoyée immédiatement au client par e-mail. Confirmer l’envoi ?';
                    if (!window.confirm(message)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                }, true);
            }
        }

        const labelNotes = function () {
            if (!notesList) return;
            notesList.querySelectorAll('li.note').forEach(function (note) {
                const content = note.querySelector('.note_content');
                if (!content || content.querySelector('.luziapi-note-kind')) return;
                const badge = document.createElement('span');
                badge.className = 'luziapi-note-kind';
                badge.textContent = note.classList.contains('customer-note')
                    ? 'NOTE CLIENT'
                    : 'HISTORIQUE INTERNE';
                content.prepend(badge);
            });
        };
        labelNotes();
        if (notesList) new MutationObserver(labelNotes).observe(notesList, {childList: true});

        if (!box || !status || !reason) return;

        const updateStatusOptions = function () {
            const mode = fulfillment ? fulfillment.value : box.dataset.fulfillment;
            const delivery = status.querySelector('option[value="wc-out-for-delivery"]');
            const pickup = status.querySelector('option[value="wc-ready-for-pickup"]');
            if (delivery && !delivery.selected) delivery.disabled = mode === 'pickup';
            if (pickup && !pickup.selected) pickup.disabled = mode === 'delivery';
        };
        updateStatusOptions();
        if (fulfillment) fulfillment.addEventListener('change', updateStatusOptions);

        const form = status.closest('form');
        if (!form) return;
        form.addEventListener('submit', function (event) {
            if (status.value === 'wc-cancelled' && reason.value.trim() === '') {
                event.preventDefault();
                window.alert('Renseignez le motif d’annulation à communiquer au client avant d’annuler la commande.');
                reason.focus();
            }
        });
    });
    </script>
    <?php
});

/**
 * @return array<string, string>
 */
function luziapi_admin_order_destination(\WC_Order $order): array
{
    $posted = static function (string $key, string $fallback): string {
        return isset($_POST[$key])
            ? sanitize_text_field(wp_unslash((string) $_POST[$key]))
            : $fallback;
    };

    $shipping = [
        'country'  => $posted('_shipping_country', $order->get_shipping_country()),
        'postcode' => $posted('_shipping_postcode', $order->get_shipping_postcode()),
        'city'     => $posted('_shipping_city', $order->get_shipping_city()),
    ];
    if ('' !== $shipping['postcode'] || '' !== $shipping['city']) {
        return $shipping;
    }

    return [
        'country'  => $posted('_billing_country', $order->get_billing_country()),
        'postcode' => $posted('_billing_postcode', $order->get_billing_postcode()),
        'city'     => $posted('_billing_city', $order->get_billing_city()),
    ];
}

function luziapi_admin_order_error(string $message): void
{
    if (class_exists('WC_Admin_Meta_Boxes')) {
        \WC_Admin_Meta_Boxes::add_error($message);
    }
}

function luziapi_update_order_fulfillment_mode(\WC_Order $order, string $newMode): bool
{
    if (! in_array($newMode, ['delivery', 'pickup'], true)) {
        return false;
    }

    $oldMode = luziapi_order_fulfillment_mode($order);
    if ($oldMode === $newMode) {
        return true;
    }

    if (luziapi_order_fulfillment_is_locked($order)) {
        luziapi_admin_order_error('Le mode de remise ne peut plus être modifié à cette étape de la commande.');

        return false;
    }

    if ('delivery' === $newMode && ! luziapi_is_local_delivery_destination(luziapi_admin_order_destination($order))) {
        luziapi_admin_order_error('La livraison est réservée aux adresses situées à Luzillé ou Bléré, 37150, France. Le mode de remise n’a pas été modifié.');

        return false;
    }

    $shippingItems = array_values($order->get_shipping_methods());
    if (count($shippingItems) > 1) {
        luziapi_admin_order_error('Plusieurs lignes d’expédition sont présentes : le mode de remise doit être vérifié manuellement.');

        return false;
    }

    $shippingItem = $shippingItems[0] ?? new \WC_Order_Item_Shipping();
    $shippingItem->set_method_id('delivery' === $newMode ? 'free_shipping' : 'local_pickup');
    $shippingItem->set_instance_id('0');
    $shippingItem->set_method_title(
        'delivery' === $newMode
            ? 'Livraison gratuite à Luzillé ou Bléré sur rendez-vous'
            : 'Retrait au domicile de LuziApi à Luzillé sur rendez-vous'
    );
    $shippingItem->set_total('0');

    if (0 === $shippingItem->get_order_id()) {
        $order->add_item($shippingItem);
    } else {
        $shippingItem->save();
    }

    $order->calculate_totals(false);
    $order->save();
    $labels = [
        'delivery' => 'Livraison à Luzillé ou Bléré sur rendez-vous',
        'pickup'   => 'Retrait à Luzillé sur rendez-vous',
        'unknown'  => 'Non renseigné',
    ];
    $order->add_order_note(
        sprintf('Mode de remise modifié : %s → %s.', $labels[$oldMode], $labels[$newMode]),
        0
    );

    return true;
}

/**
 * Sauvegarde les champs avant le statut WooCommerce : l'option sans e-mail est
 * ainsi déjà active lorsqu'une transition déclenche ses notifications.
 *
 * @param mixed $order
 */
function luziapi_save_admin_order_workflow(int $orderId, $order): void
{
    if (! $order instanceof \WC_Order
        || ! current_user_can('edit_shop_orders')
        || ! isset($_POST['luziapi_order_workflow_nonce'])
        || ! wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['luziapi_order_workflow_nonce'])),
            'luziapi_save_order_workflow'
        )) {
        return;
    }

    $oldSource = luziapi_order_source($order);
    $newSource = isset($_POST['luziapi_order_source'])
        ? sanitize_key(wp_unslash((string) $_POST['luziapi_order_source']))
        : '';
    if (! isset(luziapi_order_source_options()[$newSource])) {
        $newSource = '';
    }

    $oldEmailsDisabled = luziapi_order_emails_disabled($order);
    $newEmailsDisabled = isset($_POST['luziapi_disable_order_emails'])
        && 'yes' === sanitize_key(wp_unslash((string) $_POST['luziapi_disable_order_emails']));

    if ('' === $newSource) {
        $order->delete_meta_data(LUZIAPI_ORDER_SOURCE_META);
    } else {
        $order->update_meta_data(LUZIAPI_ORDER_SOURCE_META, $newSource);
    }
    if ($newEmailsDisabled) {
        $order->update_meta_data(LUZIAPI_ORDER_EMAILS_DISABLED_META, 'yes');
    } else {
        $order->delete_meta_data(LUZIAPI_ORDER_EMAILS_DISABLED_META);
    }
    $order->save();
    luziapi_maybe_set_admin_order_attribution($order);

    if ($oldSource !== $newSource && '' !== $newSource) {
        $order->add_order_note('Source commande : ' . luziapi_order_source_options()[$newSource] . '.', 0);
    }
    if ($oldEmailsDisabled !== $newEmailsDisabled) {
        $order->add_order_note(
            $newEmailsDisabled
                ? 'Envoi des e-mails désactivé pour cette commande.'
                : 'Envoi des e-mails réactivé pour cette commande.',
            0
        );
    }

    if (isset($_POST['luziapi_fulfillment_mode'])) {
        $newMode = sanitize_key(wp_unslash((string) $_POST['luziapi_fulfillment_mode']));
        if ('' !== $newMode) {
            luziapi_update_order_fulfillment_mode($order, $newMode);
        }
    }
}
add_action('woocommerce_process_shop_order_meta', 'luziapi_save_admin_order_workflow', 20, 2);

// Le motif est sauvegardé avant la transition de statut afin que l'e-mail
// transactionnel puisse le lire immédiatement.
add_action('woocommerce_before_order_object_save', static function ($order): void {
    if (! is_admin() || ! $order instanceof \WC_Order || ! isset($_POST['order_status'])) {
        return;
    }

    $newStatus = sanitize_key(wp_unslash((string) $_POST['order_status']));
    if ('wc-cancelled' !== $newStatus && 'cancelled' !== $newStatus) {
        return;
    }

    $reason = isset($_POST['luziapi_cancellation_reason'])
        ? sanitize_textarea_field(wp_unslash((string) $_POST['luziapi_cancellation_reason']))
        : '';

    if ('' === $reason) {
        $order->delete_meta_data('_luziapi_cancellation_reason');

        return;
    }

    $order->update_meta_data('_luziapi_cancellation_reason', $reason);
}, 10, 1);

add_action('woocommerce_order_status_cancelled', static function (int $orderId, $order = null): void {
    if (! $order instanceof \WC_Order) {
        $order = wc_get_order($orderId);
    }

    if (! $order instanceof \WC_Order) {
        return;
    }

    $reason = trim((string) $order->get_meta('_luziapi_cancellation_reason'));
    $order->add_order_note(
        '' !== $reason
            ? 'Motif d’annulation communiqué au client : ' . $reason
            : 'Aucun e-mail d’annulation envoyé au client : le motif obligatoire est absent.',
        0
    );
}, 20, 2);
