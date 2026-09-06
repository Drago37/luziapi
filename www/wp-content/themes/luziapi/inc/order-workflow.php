<?php

/**
 * Livraison locale, retrait sur rendez-vous et cycle métier des commandes.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

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
            'description' => 'Confirme que la commande a bien été livrée ou retirée.',
            'subject'     => 'Votre commande LuziApi n°{order_number} a bien été remise',
            'heading'     => 'Merci pour votre commande',
            'message'     => 'completed',
            'hooks'       => $replacedEmails['WC_Email_Customer_Completed_Order'],
        ],
    ];

    foreach ($definitions as $className => $definition) {
        $emails[$className] = new \Luziapi_Order_Status_Email($definition);
    }

    return $emails;
});
