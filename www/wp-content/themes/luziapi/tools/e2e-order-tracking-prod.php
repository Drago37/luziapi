<?php

/**
 * Test e2e du SUIVI DE COMMANDE sur la PRODUCTION.
 *
 * Piloté par scripts/e2e-tracking-prod.sh (dépose ce script à jeton, l'appelle
 * en HTTPS, le supprime). Crée UNE commande de test (marquée `_luziapi_e2e_test`)
 * pour l'adresse d'identité, joue le flux complet — accès direct, émission et
 * consommation d'un lien magique, résolution de session, isolation des notes
 * privées — puis supprime commande, produit et lignes de suivi créées.
 *
 * Mode dry-run : e-mails capturés, pas expédiés. Mode --send : le lien magique
 * part réellement vers l'adresse d'identité (à vérifier dans la boîte).
 */

declare(strict_types=1);

use LuziApi\OrderTracking\Application\Command\RedeemHistoryLink\RedeemHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RequestHistoryLink\RequestHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\StartOrderAccess\StartOrderAccessHandler;
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
use Psr\Log\NullLogger;

const LUZIAPI_TRACKING_E2E_META = '_luziapi_e2e_test';

$isCli = defined('WP_CLI') && WP_CLI;

if (! $isCli) {
    $expectedToken = 'REPLACE_WITH_TOKEN';
    $tokenNotInjected = ('REPLACE_' . 'WITH_TOKEN') === $expectedToken;
    if ($tokenNotInjected || ($_GET['k'] ?? '') !== $expectedToken) {
        http_response_code(403);
        exit('forbidden');
    }
    require __DIR__ . '/../../../wp-load.php';
    header('Content-Type: application/json; charset=utf-8');
    $embedded = base64_decode('B64PAYLOAD_PLACEHOLDER', true);
    $rawPayload = is_string($embedded) && str_starts_with($embedded, '{') ? $embedded : '';
} else {
    $rawPayload = (string) getenv('LUZIAPI_E2E_PAYLOAD');
    if ('' === $rawPayload && is_readable(__DIR__ . '/.e2e-identity.json')) {
        $rawPayload = (string) file_get_contents(__DIR__ . '/.e2e-identity.json');
    }
}

if (! function_exists('wc_create_order')) {
    echo json_encode(['fatal_error' => 'WooCommerce inactif', 'all_passed' => false]);
    exit;
}

$payload = json_decode($rawPayload ?: '[]', true);
$payload = is_array($payload) ? $payload : [];
$identity = array_merge(['email' => 'e2e@example.test', 'first_name' => 'Test'], is_array($payload['identity'] ?? null) ? $payload['identity'] : []);
$options = array_merge(['send_emails' => false], is_array($payload['options'] ?? null) ? $payload['options'] : []);
$email = (string) $identity['email'];

$GLOBALS['e2e_mails'] = [];
add_filter('wp_mail', static function (array $args): array {
    $GLOBALS['e2e_mails'][] = ['to' => (string) ($args['to'] ?? ''), 'message' => (string) ($args['message'] ?? '')];

    return $args;
}, 1);
if (empty($options['send_emails'])) {
    add_filter('pre_wp_mail', '__return_false', 99);
}

global $wpdb;
$clock = new WordPressClock();
$schema = new OrderTrackingSchemaManager($wpdb);
$schema->migrate();
$fingerprints = new WordPressAccessFingerprint(wp_salt('auth') . wp_salt('secure_auth'));
$access = new WordPressTrackingAccessRepository($wpdb, $schema, new NullLogger());
$statusHistory = new WordPressStatusHistoryRepository($wpdb, $schema, wp_timezone());
$orders = new WooCommerceOrderTrackingGateway($statusHistory);
$sessions = new TrackingSessionIssuer($access, new RandomTokenGenerator(), $fingerprints);
$urls = new WordPressTrackingUrlGenerator();

$results = [];
$assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

$orderId = 0;
$productId = 0;
$tokenFingerprints = [];
$fatal = null;

try {
    $product = new WC_Product_Simple();
    $product->set_name('E2E suivi — miel (ne pas commander)');
    $product->set_status('draft');
    $product->set_regular_price('12');
    $product->update_meta_data(LUZIAPI_TRACKING_E2E_META, 'yes');
    $productId = (int) $product->save();

    $order = wc_create_order(['status' => 'pending']);
    $order->set_billing_first_name((string) ($identity['first_name'] ?? 'Test'));
    $order->set_billing_email($email);
    $order->add_product(wc_get_product($productId), 1);
    $order->set_payment_method('cod');
    $order->add_order_note('NOTE PRIVÉE E2E — NE JAMAIS AFFICHER', false);
    $order->add_order_note('Votre commande sera prête demain.', true);
    $order->update_meta_data(LUZIAPI_TRACKING_E2E_META, 'yes');
    $order->calculate_totals();
    $order->save();
    $orderId = $order->get_id();
    $order->update_status('processing', 'E2E suivi.', false);
    $number = (string) $order->get_order_number();

    // 1. Accès direct : bon couple ouvre une session, mauvais e-mail refusé.
    $direct = new StartOrderAccessHandler($orders, $access, $sessions, $fingerprints, $clock);
    $granted = $direct->handle(new OrderAccessCredentials($number, $email), '192.0.2.10');
    $assert('Accès direct : bon numéro + e-mail ouvre une session', 'granted' === $granted->status);
    if (null !== $granted->session) {
        $tokenFingerprints[] = $fingerprints->token($granted->session->token);
    }
    $denied = $direct->handle(new OrderAccessCredentials($number, 'inconnu-' . $email), '192.0.2.11');
    $assert('Accès direct : mauvais e-mail refusé', 'denied' === $denied->status);

    // 2. Lien magique : émission (+ envoi réel en mode --send), puis capture.
    $historyReq = new RequestHistoryLinkHandler($orders, $access, new RandomTokenGenerator(), $fingerprints, $urls, new WordPressMagicLinkSender(LUZIAPI_URI . '/assets/img/logo-email.png', home_url('/'), new NullLogger()), $clock);
    $historyReq->handle($email, '192.0.2.12');
    $magicToken = '';
    foreach ($GLOBALS['e2e_mails'] as $mail) {
        if (false !== mb_stripos($mail['to'], $email) && preg_match('/acces=([A-Za-z0-9_-]{43})/', $mail['message'], $m)) {
            $magicToken = $m[1];
        }
    }
    $assert('Un lien magique a été émis vers l’adresse', '' !== $magicToken);
    $assert('Le lien magique ne contient pas l’adresse e-mail', '' === $magicToken || ! str_contains(implode('', array_column($GLOBALS['e2e_mails'], 'message')), rawurlencode($email)));

    // 3. Consommation du lien → session → résolution de la commande.
    if ('' !== $magicToken) {
        $tokenFingerprints[] = $fingerprints->token($magicToken);
        $redeem = new RedeemHistoryLinkHandler($access, $sessions, $fingerprints, $clock);
        $session = $redeem->handle($magicToken);
        $assert('Le lien magique ouvre une session', null !== $session);
        $assert('Le lien magique ne sert qu’une fois', null === $redeem->handle($magicToken));
        if (null !== $session) {
            $tokenFingerprints[] = $fingerprints->token($session->token);
            $resolver = new ResolveTrackingSessionHandler($access, $orders, $fingerprints, $clock);
            $page = $resolver->handle($session->token, 1, 10, $number);
            $assert('La session affiche la commande de test', 1 === $page?->totalOrders);
            $selected = $page?->selectedOrder;
            $updatesText = $selected ? implode("\n", array_map(static fn ($u): string => $u->title . ' ' . $u->content, $selected->updates)) : '';
            $assert('La note client est visible', str_contains($updatesText, 'prête demain'));
            $assert('La note privée n’est jamais exposée', ! str_contains($updatesText, 'NOTE PRIVÉE E2E'));
        }
    }
} catch (Throwable $e) {
    $fatal = $e->getMessage();
} finally {
    foreach (array_unique($tokenFingerprints) as $fp) {
        $wpdb->delete($schema->grantsTableName(), ['token_hash' => $fp], ['%s']);
        $wpdb->delete($schema->sessionsTableName(), ['token_hash' => $fp], ['%s']);
    }
    foreach (['order_ip' => '192.0.2.10', 'order_ip2' => '192.0.2.11', 'history_ip' => '192.0.2.12'] as $ip) {
        $wpdb->delete($schema->limitsTableName(), ['subject_hash' => $fingerprints->subject($ip)], ['%s']);
    }
    $wpdb->delete($schema->limitsTableName(), ['subject_hash' => $fingerprints->subject($email)], ['%s']);
    if ($orderId > 0 && ($o = wc_get_order($orderId)) instanceof WC_Order) {
        $wpdb->delete($schema->eventsTableName(), ['order_id' => $orderId], ['%d']);
        $o->delete(true);
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
    }
}

$failed = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));
$allPassed = null === $fatal && [] === $failed;
echo json_encode([
    'mode' => empty($options['send_emails']) ? 'dry-run' : 'send',
    'summary' => sprintf('%d/%d assertions OK', count($results) - count($failed), count($results)),
    'all_passed' => $allPassed,
    'fatal_error' => $fatal,
    'results' => $results,
    'cleanup' => ['order' => $orderId > 0, 'product' => $productId > 0],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
