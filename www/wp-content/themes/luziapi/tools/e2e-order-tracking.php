<?php

/**
 * Test d’intégration local du suivi de commande.
 *
 * Exécution : make e2e-tracking-local
 *
 * Le script utilise le vrai stockage WordPress, les commandes WooCommerce
 * (HPOS compris) et les adaptateurs du thème. Il bloque tout envoi réel et
 * supprime les commandes, le produit et les accès créés, même en cas d’échec.
 */

declare(strict_types=1);

use LuziApi\OrderTracking\Application\Command\RedeemHistoryLink\RedeemHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RequestHistoryLink\RequestHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RevokeTrackingSession\RevokeTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Command\StartOrderAccess\StartOrderAccessHandler;
use LuziApi\OrderTracking\Application\Port\MagicLinkSender;
use LuziApi\OrderTracking\Application\Query\ResolveTrackingSession\ResolveTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Service\TrackingSessionIssuer;
use LuziApi\OrderTracking\Domain\OrderAccessCredentials;
use LuziApi\OrderTracking\Infrastructure\WooCommerce\WooCommerceOrderTrackingGateway;
use LuziApi\OrderTracking\Infrastructure\WordPress\OrderTrackingSchemaManager;
use LuziApi\OrderTracking\Infrastructure\WordPress\RandomTokenGenerator;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressAccessFingerprint;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressClock;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressMagicLinkSender;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressStatusHistoryRepository;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingAccessRepository;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingUrlGenerator;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de suivi.');
}

// Aucun e-mail ne doit quitter l’environnement local pendant le test.
add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$schema = new OrderTrackingSchemaManager($wpdb);
$schema->migrate();
$clock = new WordPressClock();
$fingerprints = new WordPressAccessFingerprint('luziapi-e2e-order-tracking');
$access = new WordPressTrackingAccessRepository($wpdb, $schema, new \Psr\Log\NullLogger());
$history = new WordPressStatusHistoryRepository($wpdb, $schema, wp_timezone());
$orders = new WooCommerceOrderTrackingGateway($history);
$tokens = new RandomTokenGenerator();
$sessions = new TrackingSessionIssuer($access, $tokens, $fingerprints);
$urls = new WordPressTrackingUrlGenerator();

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$createdOrderIds = [];
$productId = 0;
$tokenFingerprints = [];
$limitRows = [];
$testSuffix = strtolower(wp_generate_password(10, false, false));
$customerEmail = 'suivi-' . $testSuffix . '@example.test';
$otherEmail = 'autre-' . $testSuffix . '@example.test';
$ipAddress = '192.0.2.42';
$capturedLink = '';

$sender = new class($capturedLink) implements MagicLinkSender {
    private string $capturedLink = '';

    public function __construct(string &$capturedLink)
    {
        $this->capturedLink = &$capturedLink;
    }

    public function send(string $email, string $accessUrl, DateTimeImmutable $expiresAt): bool
    {
        $this->capturedLink = $accessUrl;

        return true;
    }
};

$createOrder = static function (string $email, int $productId): WC_Order {
    $order = wc_create_order(['status' => 'pending']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name('Client');
    $order->set_billing_last_name('Suivi E2E');
    $order->set_billing_email($email);
    $order->set_billing_phone('0600000000');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('À la remise');
    $order->add_product(wc_get_product($productId), 2);
    $order->add_order_note('NOTE PRIVÉE E2E — NE JAMAIS AFFICHER', false);
    $order->add_order_note('Votre commande sera prête demain matin.', true);
    $order->set_customer_note('Merci de sonner au portail.');
    $order->calculate_totals();
    $order->save();

    return $order;
};

try {
    $trackingPage = get_page_by_path(WordPressTrackingUrlGenerator::PAGE_SLUG, OBJECT, 'page');
    $assert(
        'La page de suivi locale est publiée',
        $trackingPage instanceof WP_Post && 'publish' === $trackingPage->post_status,
        'Lancer make fixtures si la page manque.',
    );

    $product = new WC_Product_Simple();
    $product->set_name('E2E — Miel suivi (ne pas commander)');
    $product->set_status('draft');
    $product->set_regular_price('12');
    $productId = (int) $product->save();

    $firstOrder = $createOrder($customerEmail, $productId);
    $secondOrder = $createOrder($customerEmail, $productId);
    $customerOrders = [$firstOrder, $secondOrder];
    for ($orderNumber = 3; $orderNumber <= 12; ++$orderNumber) {
        $customerOrders[] = $createOrder($customerEmail, $productId);
    }
    $otherOrder = $createOrder($otherEmail, $productId);
    $createdOrderIds = array_map(static fn (WC_Order $order): int => $order->get_id(), $customerOrders);
    $createdOrderIds[] = $otherOrder->get_id();

    $firstOrder->update_status('processing', 'Passage E2E en traitement.', false);

    $foundIds = $orders->findOrderIdsByEmail(strtoupper($customerEmail));
    sort($foundIds);
    $expectedIds = array_map(static fn (WC_Order $order): int => $order->get_id(), $customerOrders);
    sort($expectedIds);
    $assert('La recherche par e-mail retrouve les commandes invitées', $expectedIds === $foundIds);
    $assert('La recherche par e-mail isole les autres clients', ! in_array($otherOrder->get_id(), $foundIds, true));

    $direct = new StartOrderAccessHandler($orders, $access, $sessions, $fingerprints, $clock);
    $directResult = $direct->handle(
        new OrderAccessCredentials((string) $firstOrder->get_order_number(), strtoupper($customerEmail)),
        $ipAddress,
    );
    $assert('Le numéro et l’e-mail corrects ouvrent une session', 'granted' === $directResult->status);
    if (null !== $directResult->session) {
        $tokenFingerprints[] = $fingerprints->token($directResult->session->token);
        $directPage = (new ResolveTrackingSessionHandler($access, $orders, $fingerprints, $clock))->handle(
            $directResult->session->token,
            1,
            10,
            (string) $firstOrder->get_order_number(),
        );
        $assert('L’accès direct ne donne accès qu’à une commande', 1 === $directPage?->totalOrders);
    }

    $denied = $direct->handle(
        new OrderAccessCredentials((string) $firstOrder->get_order_number(), $otherEmail),
        '192.0.2.43',
    );
    $assert('Une mauvaise adresse e-mail est refusée', 'denied' === $denied->status);

    $historyRequest = new RequestHistoryLinkHandler(
        $orders,
        $access,
        $tokens,
        $fingerprints,
        $urls,
        $sender,
        $clock,
    );
    $requestResult = $historyRequest->handle($customerEmail, '192.0.2.44');
    $assert('La demande d’historique produit un lien', $requestResult->messageSent && '' !== $capturedLink);

    $query = [];
    parse_str((string) parse_url($capturedLink, PHP_URL_QUERY), $query);
    $magicToken = is_string($query['acces'] ?? null) ? $query['acces'] : '';
    $assert('Le jeton magique est opaque et bien formé', 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $magicToken));
    if ('' !== $magicToken) {
        $tokenFingerprints[] = $fingerprints->token($magicToken);
    }

    $redeem = new RedeemHistoryLinkHandler($access, $sessions, $fingerprints, $clock);
    $historySession = $redeem->handle($magicToken);
    $assert('Le lien magique crée une session', null !== $historySession);
    $assert('Le lien magique ne peut servir qu’une fois', null === $redeem->handle($magicToken));

    if (null !== $historySession) {
        $tokenFingerprints[] = $fingerprints->token($historySession->token);
        $resolver = new ResolveTrackingSessionHandler($access, $orders, $fingerprints, $clock);
        $historyPage = $resolver->handle(
            $historySession->token,
            1,
            10,
            (string) $firstOrder->get_order_number(),
        );
        $assert('La session historique contient les douze commandes du client', 12 === $historyPage?->totalOrders);
        $assert('La première page est limitée à dix commandes', 10 === count($historyPage?->orders ?? []));
        $secondPage = $resolver->handle($historySession->token, 2, 10, '');
        $assert(
            'La pagination restitue les deux commandes suivantes sans changer le périmètre',
            2 === count($secondPage?->orders ?? []) && 12 === $secondPage?->totalOrders,
        );

        $selected = $historyPage?->selectedOrder;
        $updatesText = null !== $selected
            ? implode("\n", array_map(static fn ($update): string => $update->title . ' ' . $update->content, $selected->updates))
            : '';
        $assert('La note publique apparaît dans l’historique', str_contains($updatesText, 'Votre commande sera prête'));
        $assert('La note privée ne quitte jamais WooCommerce', ! str_contains($updatesText, 'NOTE PRIVÉE E2E'));
        $recordedTransitions = $history->forOrderIds([$firstOrder->get_id()]);
        $firstTransitions = $recordedTransitions[$firstOrder->get_id()] ?? [];
        $assert(
            'Le changement de statut est historisé dans la table dédiée',
            1 === count($firstTransitions) && 'processing' === $firstTransitions[0]->toStatus,
        );
        $assert('Le statut historisé est lisible par le client', str_contains($updatesText, 'En préparation'));
        $assert('La note client de la commande est affichée', 'Merci de sonner au portail.' === $selected?->customerNote);

        (new RevokeTrackingSessionHandler($access, $fingerprints))->handle($historySession->token);
        $assert(
            'La déconnexion révoque immédiatement la session',
            null === $resolver->handle($historySession->token, 1, 10, ''),
        );
    }

    $mailCapture = [];
    $mailFilter = static function ($shortCircuit, array $attributes) use (&$mailCapture): bool {
        $mailCapture = $attributes;

        return true;
    };
    remove_filter('pre_wp_mail', '__return_false', 999);
    add_filter('pre_wp_mail', $mailFilter, 999, 2);
    (new WordPressMagicLinkSender(
        LUZIAPI_URI . '/assets/img/logo-email.png',
        home_url('/'),
        new \Psr\Log\NullLogger(),
    ))->send($customerEmail, $urls->forToken(str_repeat('Z', 43)), $clock->now()->modify('+15 minutes'));
    remove_filter('pre_wp_mail', $mailFilter, 999);
    add_filter('pre_wp_mail', '__return_false', 999);
    $mailBody = (string) ($mailCapture['message'] ?? '');
    $assert('L’e-mail du lien magique est intercepté sans envoi réel', [] !== $mailCapture);
    $assert('L’e-mail annonce la durée et le caractère unique du lien', str_contains($mailBody, 'une seule fois') && str_contains($mailBody, '2 heures'));
    $assert('L’e-mail ne divulgue aucune commande', ! str_contains($mailBody, (string) $firstOrder->get_order_number()));

    // #1 : un échec d'envoi du lien magique doit être tracé (réponse inchangée).
    $mailFailLog = new \Monolog\Handler\TestHandler();
    $mailFailLogger = new \Monolog\Logger('e2e-suivi');
    $mailFailLogger->pushHandler($mailFailLog);
    $sentOnFailure = (new WordPressMagicLinkSender(
        LUZIAPI_URI . '/assets/img/logo-email.png',
        home_url('/'),
        $mailFailLogger,
    ))->send($customerEmail, $urls->forToken(str_repeat('Y', 43)), $clock->now()->modify('+15 minutes'));
    $assert('Un échec d’envoi du lien magique est tracé', false === $sentOnFailure && $mailFailLog->hasErrorRecords());

    $commonEmailData = luziapi_customer_email_common_data($firstOrder);
    $trackingUrl = (string) ($commonEmailData['tracking_url'] ?? '');
    $assert('Les e-mails de commande proposent le suivi quand la page existe', str_contains($trackingUrl, 'suivi-commande'));
    $assert('Le lien d’e-mail préremplit seulement le numéro', str_contains($trackingUrl, 'commande=') && ! str_contains($trackingUrl, rawurlencode($customerEmail)));

    $limitRows = [
        ['order_ip', $fingerprints->subject($ipAddress)],
        ['order_credentials', $fingerprints->subject($firstOrder->get_order_number() . '|' . strtolower($customerEmail))],
        ['order_ip', $fingerprints->subject('192.0.2.43')],
        ['order_credentials', $fingerprints->subject($firstOrder->get_order_number() . '|' . strtolower($otherEmail))],
        ['history_email', $fingerprints->subject($customerEmail)],
        ['history_ip', $fingerprints->subject('192.0.2.44')],
    ];
} catch (Throwable $exception) {
    $assert('Le scénario d’intégration se termine sans exception', false, $exception->getMessage());
} finally {
    foreach (array_unique($tokenFingerprints) as $fingerprint) {
        $wpdb->delete($schema->grantsTableName(), ['token_hash' => $fingerprint], ['%s']);
        $wpdb->delete($schema->sessionsTableName(), ['token_hash' => $fingerprint], ['%s']);
    }
    foreach ($limitRows as [$scope, $subject]) {
        $wpdb->delete($schema->limitsTableName(), ['scope' => $scope, 'subject_hash' => $subject], ['%s', '%s']);
    }
    foreach ($createdOrderIds as $orderId) {
        $wpdb->delete($schema->eventsTableName(), ['order_id' => $orderId], ['%d']);
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
}

$failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['success']));
foreach ($results as $result) {
    WP_CLI::log(sprintf(
        '%s %s%s',
        $result['success'] ? '✓' : '✗',
        $result['label'],
        '' !== $result['detail'] ? ' — ' . $result['detail'] : '',
    ));
}

if ([] !== $failed) {
    WP_CLI::error(sprintf('%d assertion(s) en échec sur %d.', count($failed), count($results)));
}

WP_CLI::success(sprintf('%d assertions de suivi validées ; données E2E supprimées.', count($results)));
