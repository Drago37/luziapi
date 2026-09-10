<?php

/**
 * Test de bout en bout du process de commande LuziApi.
 *
 * Un seul moteur, deux façons de le lancer (voir docs/tests-e2e-commandes.md) :
 *   - Local : docker compose run --rm wpcli wp eval-file \
 *             wp-content/themes/luziapi/tools/e2e-orders.php
 *   - Prod  : déployé en FTPS dans tools/, puis appelé en HTTPS avec un jeton ;
 *             le script est supprimé après usage.
 *
 * Il crée des commandes de test (marquées `_luziapi_e2e_test`), joue plusieurs
 * scénarios en vérifiant les effets réels (e-mails, statuts, planification,
 * stock, motif d'annulation), puis **supprime toute trace** — dans tous les cas,
 * même en cas d'erreur. Aucune donnée personnelle n'est écrite dans ce fichier :
 * l'identité de test est fournie au lancement.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------- */
/*  Chargement de WordPress + contexte (CLI WP-CLI ou HTTP à jeton)            */
/* -------------------------------------------------------------------------- */

const LUZIAPI_E2E_TEST_META = '_luziapi_e2e_test';

$isCli = 'cli' === PHP_SAPI || defined('WP_CLI');

if (! $isCli) {
    // Recherche ascendante de wp-load.php (indépendant de la profondeur).
    $dir = __DIR__;
    for ($i = 0; $i < 8 && ! is_readable($dir . '/wp-load.php'); ++$i) {
        $dir = dirname($dir);
    }
    require $dir . '/wp-load.php';

    // Jeton injecté au déploiement (remplacé par sed). Sans remplacement, refus.
    // Le témoin « non injecté » est reconstruit par concaténation pour que le sed,
    // qui cherche la chaîne contiguë, ne le remplace pas lui aussi.
    $expectedToken    = 'REPLACE_WITH_TOKEN';
    $tokenNotInjected = ('REPLACE_' . 'WITH_TOKEN') === $expectedToken;
    if ($tokenNotInjected || ($_GET['k'] ?? '') !== $expectedToken) {
        http_response_code(403);
        exit('forbidden');
    }

    header('Content-Type: application/json; charset=utf-8');

    // Payload embarqué (base64 injecté au déploiement) prioritaire sur le corps
    // POST : évite toute donnée personnelle dans l'URL ou les logs d'accès. Le
    // marqueur par défaut n'est pas du base64 valide (caractère « _ »).
    $embeddedPayload = base64_decode('B64PAYLOAD_PLACEHOLDER', true);
    $rawPayload = is_string($embeddedPayload) && str_starts_with($embeddedPayload, '{')
        ? $embeddedPayload
        : (string) file_get_contents('php://input');
}

if ($isCli) {
    // Payload local : variable d'environnement JSON, ou fichier ignoré par git.
    $rawPayload = (string) getenv('LUZIAPI_E2E_PAYLOAD');
    if ('' === $rawPayload && is_readable(__DIR__ . '/.e2e-identity.json')) {
        $rawPayload = (string) file_get_contents(__DIR__ . '/.e2e-identity.json');
    }
}

$payload = '' !== $rawPayload ? json_decode($rawPayload, true) : [];
if (! is_array($payload)) {
    $payload = [];
}

$identity = array_merge([
    'email'      => 'e2e@example.test',
    'first_name' => 'Test',
    'last_name'  => 'E2E',
    'phone'      => '0600000000',
    'address_1'  => '1 rue des Trois Cheminées',
    'city'       => 'Luzillé',
    'postcode'   => '37150',
    'country'    => 'FR',
], is_array($payload['identity'] ?? null) ? $payload['identity'] : []);

$options = array_merge([
    'send_emails'  => false,
    'quantity'     => 2,
    'cleanup_only' => false,
    'scenarios'    => ['delivery', 'pickup', 'cancel', 'paid_on_time', 'cancel_no_reason', 'manual_no_email'],
], is_array($payload['options'] ?? null) ? $payload['options'] : []);

/* -------------------------------------------------------------------------- */
/*  Outillage : capture des e-mails, assertions, notes                        */
/* -------------------------------------------------------------------------- */

$GLOBALS['e2e_mails'] = [];

add_filter('wp_mail', static function (array $args): array {
    $GLOBALS['e2e_mails'][] = [
        'to'      => is_array($args['to'] ?? '') ? implode(',', $args['to']) : (string) ($args['to'] ?? ''),
        'subject' => (string) ($args['subject'] ?? ''),
        'message' => (string) ($args['message'] ?? ''),
    ];

    return $args;
}, 1);

if (empty($options['send_emails'])) {
    // Dry-run : les e-mails sont journalisés mais pas réellement expédiés.
    add_filter('pre_wp_mail', '__return_false', 99);
}

// Capture des pièces jointes calculées à la construction de chaque e-mail
// (indépendant de l'envoi réel : fonctionne aussi en dry-run).
$GLOBALS['e2e_attachments'] = [];
add_filter('woocommerce_email_attachments', static function ($attachments, string $emailId = '', $object = null) {
    foreach ((array) $attachments as $file) {
        $GLOBALS['e2e_attachments'][] = ['email' => $emailId, 'file' => basename((string) $file)];
    }

    return $attachments;
}, 99, 3);

$results = [];
$assert  = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok] + ('' !== $detail ? ['detail' => $detail] : []);
};

$mailResetCount = 0;
$resetMail = static function () use (&$mailResetCount): void {
    $GLOBALS['e2e_mails'] = [];
    ++$mailResetCount;
};

// On ne regarde que les e-mails adressés au client : les notifications admin
// WooCommerce (ex. « La commande a été annulée » envoyée à la boutique)
// partagent des mots-clés et fausseraient les assertions.
$customerEmail = (string) ($identity['email'] ?? '');
$trackingAvailable = class_exists(\LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingUrlGenerator::class)
    && '' !== (new \LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingUrlGenerator())->publishedPageUrl();
$mailSent = static function (string $needle) use ($customerEmail): bool {
    foreach ($GLOBALS['e2e_mails'] as $mail) {
        if ('' !== $customerEmail && false === mb_stripos($mail['to'], $customerEmail)) {
            continue;
        }
        if (false !== mb_stripos($mail['subject'], $needle)) {
            return true;
        }
    }

    return false;
};

$assertEmailTemplate = static function (string $label, string $subjectNeedle) use ($assert, $customerEmail, $trackingAvailable): void {
    $message = '';
    foreach ($GLOBALS['e2e_mails'] as $mail) {
        if ('' !== $customerEmail && false === mb_stripos($mail['to'], $customerEmail)) {
            continue;
        }
        if (false !== mb_stripos($mail['subject'], $subjectNeedle)) {
            $message = $mail['message'];
            break;
        }
    }

    $expected = [
        'id="luziapi-email-card"',
        'logo-email.png',
        'par e-mail et/ou SMS',
        'CM2C',
        'TVA non applicable',
    ];
    $missing = array_values(array_filter(
        $expected,
        static fn (string $needle): bool => false === mb_stripos($message, $needle)
    ));
    $hasTracking = false !== mb_stripos($message, 'Suivre ma commande');
    $isValid = '' !== $message && [] === $missing && $trackingAvailable === $hasTracking;

    $assert(
        $label . ' — gabarit LuziApi complet',
        $isValid,
        $isValid
            ? ''
            : ([] !== $missing
                ? 'éléments absents : ' . implode(', ', $missing)
                : ($trackingAvailable ? 'lien de suivi absent' : 'lien de suivi présent sans page publiée'))
    );
};

$assertAdminEmailTemplate = static function (string $label) use ($assert, $customerEmail): void {
    $message = '';
    foreach ($GLOBALS['e2e_mails'] as $mail) {
        if ('' !== $customerEmail && false !== mb_stripos($mail['to'], $customerEmail)) {
            continue;
        }
        if (false !== mb_stripos($mail['message'], 'luziapi-admin-internal')) {
            $message = $mail['message'];
            break;
        }
    }

    $expected = [
        'id="luziapi-email-card"',
        'Notification interne',
        'Ouvrir la commande',
        'Mode de remise',
        'logo-email.png',
    ];
    $missing = array_values(array_filter(
        $expected,
        static fn (string $needle): bool => false === mb_stripos($message, $needle)
    ));
    $hasCustomerMarketing = false !== mb_stripos($message, 'actualités LuziApi')
        || false !== mb_stripos($message, 'CM2C');

    $assert(
        $label . ' — gabarit administrateur complet',
        '' !== $message && [] === $missing && ! $hasCustomerMarketing,
        [] !== $missing
            ? 'éléments absents : ' . implode(', ', $missing)
            : ($hasCustomerMarketing ? 'contenu client inutile présent dans la notification interne' : '')
    );
};

$assertNativeCustomerEmailTemplates = static function (\WC_Order $order) use ($assert, $trackingAvailable): void {
    $emails = WC()->mailer()->get_emails();
    $definitions = [
        'WC_Email_Customer_Failed_Order' => ['Paiement non abouti', null],
        'WC_Email_Customer_Refunded_Order' => ['Commande remboursée', null],
        'WC_Email_Customer_Note' => ['Nouveau message', 'Note de test E2E'],
        'WC_Email_Customer_Invoice' => ['Détails de la commande', null],
    ];

    foreach ($definitions as $className => [$expectedLabel, $customerNote]) {
        $email = $emails[$className] ?? null;
        if (! $email instanceof \WC_Email) {
            $assert('E-mail natif ' . $className . ' disponible', false);
            continue;
        }

        $email->object = $order;
        if ($email instanceof \WC_Email_Customer_Note) {
            $email->customer_note = (string) $customerNote;
        }
        if ($email instanceof \WC_Email_Customer_Refunded_Order) {
            $email->partial_refund = false;
            $email->refund         = false;
        }

        $message      = $email->get_content_html();
        $plainMessage = $email->get_content_plain();
        $expected = [
            'id="luziapi-email-card"',
            'logo-email.png',
            'par e-mail et/ou SMS',
            'CM2C',
            'TVA non applicable',
            $expectedLabel,
        ];
        if (is_string($customerNote)) {
            $expected[] = $customerNote;
        }
        if ($trackingAvailable) {
            $expected[] = 'Suivre ma commande';
        }
        $missing = array_values(array_filter(
            $expected,
            static fn (string $needle): bool => false === mb_stripos($message, $needle)
        ));
        $plainExpected = [
            'LUZIAPI — MIEL ARTISANAL',
            'ACTUALITÉS LUZIAPI',
            'CM2C',
            'TVA non applicable',
            $expectedLabel,
        ];
        if (is_string($customerNote)) {
            $plainExpected[] = $customerNote;
        }
        if ($trackingAvailable) {
            $plainExpected[] = 'SUIVRE MA COMMANDE';
        }
        $plainMissing = array_values(array_filter(
            $plainExpected,
            static fn (string $needle): bool => false === mb_stripos($plainMessage, $needle)
        ));

        $assert(
            sprintf('E-mail natif %s — gabarits HTML et texte LuziApi complets', $email->id),
            'emails/luziapi-customer-order.php' === $email->template_html
                && 'emails/plain/luziapi-customer-order.php' === $email->template_plain
                && [] === $missing
                && [] === $plainMissing,
            [] !== $missing
                ? 'éléments HTML absents : ' . implode(', ', $missing)
                : ([] !== $plainMissing ? 'éléments texte absents : ' . implode(', ', $plainMissing) : '')
        );
    }
};

$orderHasNote = static function (int $orderId, string $needle): bool {
    $notes = wc_get_order_notes(['order_id' => $orderId, 'limit' => 50]);
    foreach ($notes as $note) {
        if (false !== mb_stripos((string) $note->content, $needle)) {
            return true;
        }
    }

    return false;
};

/* -------------------------------------------------------------------------- */
/*  Fabrique : produit et commandes de test                                   */
/* -------------------------------------------------------------------------- */

$getTestProduct = static function (): int {
    $existing = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'any',
        'meta_key'       => LUZIAPI_E2E_TEST_META,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $product = new \WC_Product_Simple();
    $product->set_name('E2E — Miel de test (ne pas commander)');
    $product->set_status('publish');
    $product->set_catalog_visibility('hidden');
    $product->set_regular_price('10');
    $product->set_manage_stock(true);
    $product->set_stock_quantity(1000);
    $id = (int) $product->save();
    update_post_meta($id, LUZIAPI_E2E_TEST_META, '1');

    return $id;
};

$makeOrder = static function (
    array $identity,
    string $paymentMethod,
    string $shippingMethodId,
    int $productId,
    int $qty
): \WC_Order {
    $order = wc_create_order(['status' => 'pending']);

    foreach (['first_name', 'last_name', 'address_1', 'city', 'postcode', 'country', 'phone', 'email'] as $field) {
        $order->{'set_billing_' . $field}($identity[$field] ?? '');
    }
    foreach (['first_name', 'last_name', 'address_1', 'city', 'postcode', 'country'] as $field) {
        $order->{'set_shipping_' . $field}($identity[$field] ?? '');
    }

    $order->add_product(wc_get_product($productId), $qty);

    if ('' !== $shippingMethodId) {
        $shipping = new \WC_Order_Item_Shipping();
        $shipping->set_method_id($shippingMethodId);
        $shipping->set_method_title('local_pickup' === $shippingMethodId ? 'Retrait sur rendez-vous' : 'Livraison locale');
        $shipping->set_total(0);
        $order->add_item($shipping);
    }

    $order->set_payment_method($paymentMethod);
    $order->set_payment_method_title('bacs' === $paymentMethod ? 'Virement bancaire ou WERO' : 'À la remise');
    $order->update_meta_data(LUZIAPI_E2E_TEST_META, '1');
    $order->calculate_totals();
    $order->save();

    return $order;
};

$pendingDeadlineActions = static function (int $orderId, string $hook): int {
    if (! function_exists('as_get_scheduled_actions')) {
        return -1;
    }

    return count((array) as_get_scheduled_actions([
        'hook'     => $hook,
        'args'     => [$orderId],
        'group'    => 'luziapi',
        'status'   => 'pending',
        'per_page' => 20,
    ], 'ids'));
};

/* -------------------------------------------------------------------------- */
/*  Nettoyage : supprime toute commande et tout produit marqués de test       */
/* -------------------------------------------------------------------------- */

$cleanup = static function () use (&$createdOrders): array {
    $removed = ['orders' => 0, 'products' => 0];

    $ids = wc_get_orders([
        'limit'      => -1,
        'status'     => array_keys(wc_get_order_statuses()),
        'return'     => 'ids',
        'meta_query' => [[
            'key'   => LUZIAPI_E2E_TEST_META,
            'value' => '1',
        ]],
    ]);
    $ids = array_unique(array_merge((array) $ids, $createdOrders ?? []));

    foreach ($ids as $orderId) {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            continue;
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('luziapi_bacs_payment_reminder', [$orderId], 'luziapi');
            as_unschedule_all_actions('luziapi_bacs_payment_expiry', [$orderId], 'luziapi');
        }
        $order = wc_get_order($orderId);
        if ($order) {
            $order->delete(true);
            ++$removed['orders'];
        }
    }

    $products = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'any',
        'meta_key'       => LUZIAPI_E2E_TEST_META,
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    foreach ($products as $productId) {
        wp_delete_post((int) $productId, true);
        ++$removed['products'];
    }

    return $removed;
};

/* -------------------------------------------------------------------------- */
/*  Exécution                                                                  */
/* -------------------------------------------------------------------------- */

$createdOrders = [];
$fatal         = null;

if (! empty($options['cleanup_only'])) {
    $removed = $cleanup();
    echo json_encode(['mode' => 'cleanup_only', 'removed' => $removed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

    return;
}

try {
    $productId = $getTestProduct();
    $qty       = max(1, (int) $options['quantity']);
    $scenarios = (array) $options['scenarios'];

    /* ---- Scénario : commande avec livraison locale --------------------- */
    if (in_array('delivery', $scenarios, true)) {
        $order = $makeOrder($identity, 'cod', 'free_shipping', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $assert('Livraison — mode de remise détecté « delivery »', 'delivery' === luziapi_order_fulfillment_mode($order));

        $resetMail();
        $order->update_status('processing');
        $assert('Livraison — e-mail « confirmée » envoyé', $mailSent('confirmée'));
        $assertEmailTemplate('Livraison — e-mail « confirmée »', 'confirmée');
        $assertAdminEmailTemplate('Livraison — e-mail administrateur « nouvelle commande »');
        // La version CGV est figée au checkout (hors périmètre programmatique) ;
        // ici on vérifie ce que l'e-mail déclenche : le PDF CGV en pièce jointe.
        $cgvAttached = false;
        foreach ($GLOBALS['e2e_attachments'] as $attachment) {
            if ('luziapi_customer_processing' === $attachment['email']
                && false !== stripos($attachment['file'], 'LuziApi-CGV-')) {
                $cgvAttached = true;
                break;
            }
        }
        $assert('Livraison — PDF CGV joint au 1er e-mail de confirmation', $cgvAttached);

        $resetMail();
        $order->update_status('out-for-delivery');
        $assert('Livraison — e-mail « en cours de livraison » envoyé', $mailSent('livraison'));
        $assertEmailTemplate('Livraison — e-mail « en cours de livraison »', 'livraison');
        $assert(
            'Livraison — trace privée de l’e-mail ajoutée',
            $orderHasNote($id, 'E-mail client « Organisons la livraison')
        );

        $assertNativeCustomerEmailTemplates($order);

        $resetMail();
        $order->update_status('ready-for-pickup');
        $assert('Livraison — e-mail « prête au retrait » BLOQUÉ (mode incohérent)', ! $mailSent('prête au retrait'));
        $assert('Livraison — note de blocage ajoutée', $orderHasNote($id, 'ne correspond pas au mode de remise'));

        $resetMail();
        $order->update_status('completed');
        $assert('Livraison — e-mail « terminée » envoyé', $mailSent('remise'));
        $assertEmailTemplate('Livraison — e-mail « terminée »', 'remise');
    }

    /* ---- Scénario : commande avec retrait ------------------------------ */
    if (in_array('pickup', $scenarios, true)) {
        $order = $makeOrder($identity, 'cod', 'local_pickup', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $assert('Retrait — mode de remise détecté « pickup »', 'pickup' === luziapi_order_fulfillment_mode($order));

        $resetMail();
        $order->update_status('processing');
        $order->update_status('ready-for-pickup');
        $assert('Retrait — e-mail « prête au retrait » envoyé', $mailSent('prête au retrait'));
        $assertEmailTemplate('Retrait — e-mail « prête au retrait »', 'prête au retrait');

        $resetMail();
        $order->update_status('out-for-delivery');
        $assert('Retrait — e-mail « en cours de livraison » BLOQUÉ (mode incohérent)', ! $mailSent('livraison'));
        $assert('Retrait — note de blocage ajoutée', $orderHasNote($id, 'ne correspond pas au mode de remise'));

        $order->update_status('completed');
    }

    /* ---- Scénario : annulation pour non-paiement (virement/WERO) ------- */
    if (in_array('cancel', $scenarios, true)) {
        $order = $makeOrder($identity, 'bacs', 'free_shipping', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $stockBefore = (int) wc_get_product($productId)->get_stock_quantity();

        $resetMail();
        $order->update_status('on-hold');
        $assert('Annulation — e-mail « en attente » envoyé', $mailSent('en attente'));
        $assertEmailTemplate('Annulation — e-mail « en attente »', 'en attente');
        $assert('Annulation — rappel planifié (1 action)', 1 === $pendingDeadlineActions($id, 'luziapi_bacs_payment_reminder'));
        $assert('Annulation — expiration planifiée (1 action)', 1 === $pendingDeadlineActions($id, 'luziapi_bacs_payment_expiry'));

        $resetMail();
        do_action('luziapi_bacs_payment_reminder', $id);
        $assert('Annulation — e-mail de rappel envoyé', $mailSent('Rappel'));
        $assertEmailTemplate('Annulation — e-mail de rappel', 'Rappel');

        $resetMail();
        do_action('luziapi_bacs_payment_expiry', $id);
        $order = wc_get_order($id);
        $assert('Annulation — commande passée à « annulée »', 'cancelled' === $order->get_status());
        $assert('Annulation — e-mail d’annulation envoyé (motif présent)', $mailSent('annulée'));
        $assertEmailTemplate('Annulation — e-mail d’annulation', 'annulée');
        $assertAdminEmailTemplate('Annulation — e-mail administrateur « commande annulée »');
        $assert('Annulation — motif enregistré sur la commande', '' !== trim((string) $order->get_meta('_luziapi_cancellation_reason')));

        $stockAfter = (int) wc_get_product($productId)->get_stock_quantity();
        $assert(
            'Annulation — stock restauré',
            $stockAfter >= $stockBefore,
            sprintf('avant=%d après=%d', $stockBefore, $stockAfter)
        );
    }

    /* ---- Scénario : virement payé à temps (déprogrammation) ------------ */
    if (in_array('paid_on_time', $scenarios, true)) {
        $order = $makeOrder($identity, 'bacs', 'local_pickup', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $order->update_status('on-hold');
        $scheduled = 1 === $pendingDeadlineActions($id, 'luziapi_bacs_payment_expiry');
        $order->update_status('processing');
        $stillScheduled = $pendingDeadlineActions($id, 'luziapi_bacs_payment_reminder')
            + $pendingDeadlineActions($id, 'luziapi_bacs_payment_expiry');

        $assert('Payé à temps — échéance bien planifiée à la mise en attente', $scheduled);
        $assert('Payé à temps — échéances déprogrammées après paiement', 0 === $stillScheduled);
    }

    /* ---- Scénario : annulation manuelle sans motif (garde e-mail) ------ */
    if (in_array('cancel_no_reason', $scenarios, true)) {
        $order = $makeOrder($identity, 'bacs', 'free_shipping', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $order->update_status('on-hold');
        $resetMail();
        $order->update_status('cancelled');
        $assert('Sans motif — e-mail d’annulation NON envoyé', ! $mailSent('annulée'));
        $order = wc_get_order($id);
        $assert('Sans motif — note « motif obligatoire absent » ajoutée', $orderHasNote($id, 'motif obligatoire est absent'));
    }

    /* ---- Scénario : commande manuelle suivie sans aucun e-mail -------- */
    if (in_array('manual_no_email', $scenarios, true)) {
        $order = $makeOrder($identity, 'cod', '', $productId, $qty);
        $createdOrders[] = $order->get_id();
        $id = $order->get_id();

        $stockBefore = (int) wc_get_product($productId)->get_stock_quantity();
        $previousUserId = get_current_user_id();
        if (! current_user_can('edit_shop_orders')) {
            $workflowUsers = get_users([
                'capability' => 'edit_shop_orders',
                'number'     => 1,
                'fields'     => 'ids',
            ]);
            if ([] === $workflowUsers) {
                throw new \RuntimeException('Aucun utilisateur autorisé à modifier les commandes pour le scénario manuel.');
            }
            wp_set_current_user((int) $workflowUsers[0]);
        }

        $previousPost = $_POST;
        $_POST = [
            'luziapi_order_workflow_nonce' => wp_create_nonce('luziapi_save_order_workflow'),
            'luziapi_order_source'         => 'phone',
            'luziapi_disable_order_emails' => 'yes',
            'luziapi_fulfillment_mode'     => 'pickup',
        ];
        luziapi_save_admin_order_workflow($id, $order);
        $_POST = $previousPost;
        wp_set_current_user($previousUserId);
        $order = wc_get_order($id);

        $assert('Commande manuelle — source « Téléphone » enregistrée depuis la fiche', $order instanceof \WC_Order && 'phone' === luziapi_order_source($order));
        $assert(
            'Commande manuelle — attribution native « Administration web »',
            $order instanceof \WC_Order && 'admin' === $order->get_meta(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META)
        );
        $assert('Commande manuelle — retrait ajouté depuis la fiche à une commande sans expédition', $order instanceof \WC_Order && 'pickup' === luziapi_order_fulfillment_mode($order));
        $assert('Commande manuelle — suppression des e-mails enregistrée depuis la fiche', $order instanceof \WC_Order && luziapi_order_emails_disabled($order));

        $resetMail();
        $order->update_status('processing');
        $order->update_status('ready-for-pickup');
        $order->update_status('completed');
        $order = wc_get_order($id);

        $assert('Commande manuelle — processus mené jusqu’à « Terminée »', $order instanceof \WC_Order && 'completed' === $order->get_status());
        $assert('Commande manuelle — aucun e-mail client ni administrateur généré', [] === $GLOBALS['e2e_mails']);
        $assert(
            'Commande manuelle — stock décrémenté une seule fois',
            $stockBefore - $qty === (int) wc_get_product($productId)->get_stock_quantity()
        );
        $assert(
            'Commande manuelle — suppression intentionnelle tracée en note privée',
            $orderHasNote($id, 'désactivé pour cette commande')
        );

        $resetMail();
        $order->add_order_note('Message client de test sans envoi.', 1, true);
        $assert(
            'Commande manuelle — une note client reste sans e-mail lorsque la suppression est active',
            [] === $GLOBALS['e2e_mails']
        );
    }
} catch (\Throwable $e) {
    $fatal = $e->getMessage();
} finally {
    $removed = $cleanup();
}

$passed = count(array_filter($results, static fn (array $r): bool => $r['ok']));
$total  = count($results);

echo json_encode([
    'mode'         => empty($options['send_emails']) ? 'dry-run (aucun e-mail expédié)' : 'e-mails réellement expédiés',
    'summary'      => sprintf('%d/%d assertions OK', $passed, $total),
    'all_passed'   => $fatal === null && $passed === $total,
    'fatal_error'  => $fatal,
    'results'      => $results,
    'mails_logged' => array_map(
        static fn (array $mail): array => ['to' => $mail['to'], 'subject' => $mail['subject']],
        $GLOBALS['e2e_mails']
    ),
    'cleanup'      => $removed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
