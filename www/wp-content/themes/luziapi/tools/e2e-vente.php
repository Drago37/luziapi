<?php

/**
 * Test d’intégration local de la « Vente » (création de commande unifiée).
 *
 * Exécution : make e2e-vente-local
 *
 * Le script utilise le vrai WordPress, les commandes WooCommerce (HPOS compris)
 * et les adaptateurs du thème. Il rend réellement le contrôleur de la Vente pour
 * vérifier le préremplissage depuis le répertoire client et le sélecteur de
 * client, puis contrôle la garde du point d’entrée unique. Aucun e-mail n’est
 * envoyé et toutes les données créées sont supprimées, même en cas d’échec.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Pilotage\Application\Query\GetCustomerDirectory\GetCustomerDirectoryQuery;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceCustomerTimelineRepository;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceProductCatalog;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedCustomerCategoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressCustomerCategoryRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;
use LuziApi\Pilotage\UserInterface\Admin\QuickSaleController;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de la Vente.');
}
if (! function_exists('luziapi_is_native_order_creation_screen')) {
    WP_CLI::error('Le thème LuziApi doit être actif (inc/woocommerce.php introuvable).');
}

// Aucun e-mail ne doit quitter l’environnement local pendant le test.
add_filter('pre_wp_mail', '__return_false', 999);

// La Vente exige la capacité de gérer les commandes. En contexte WP-CLI, cette
// capacité WooCommerce n'est pas toujours présente : on l'accorde le temps du
// test via un filtre (retiré à la fin), sans rien modifier en base.
$grantOrdersCap = static function (array $allcaps): array {
    $allcaps['edit_shop_orders'] = true;

    return $allcaps;
};
add_filter('user_has_cap', $grantOrdersCap);

global $wpdb;

$clock = new WordPressClock();
$orders = new WooCommerceOrderRepository($clock->timezone());
$products = new WooCommerceProductCatalog();
$schema = new PilotageSchemaManager($wpdb);
$activity = new ActivityRecorder(new WordPressActivityRepository($wpdb, $schema, $clock->timezone()), $clock);
$categories = new AuditedCustomerCategoryRepository(new WordPressCustomerCategoryRepository($wpdb, $schema), $activity);
$receipts = new AuditedReceiptRepository(new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()), $activity);
$customerHandler = new GetCustomerDirectoryHandler(
    $orders,
    new CustomerHistoryProjector(),
    $categories,
    $receipts,
    new WooCommerceCustomerTimelineRepository($clock->timezone()),
    $clock,
);
$controller = new QuickSaleController(
    $products,
    new CreateQuickSaleHandler(new WooCommerceQuickSaleOrderWriter(), new RecordReceiptHandler($receipts, $clock), $clock),
    $customerHandler,
    $clock,
    $activity,
);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$render = static function (array $get) use ($controller): string {
    $backup = $_GET;
    $_GET = $get;
    ob_start();
    try {
        $controller->render();
    } finally {
        $html = (string) ob_get_clean();
        $_GET = $backup;
    }

    return $html;
};

$createdOrderIds = [];
$productId = 0;
$suffix = strtolower(wp_generate_password(8, false, false));
$alice = ['name' => 'Alice Suivi', 'email' => 'alice-' . $suffix . '@example.test', 'phone' => '0600' . random_int(100000, 199999), 'city' => 'Luzillé'];
$bob = ['name' => 'Bob Passage', 'email' => 'bob-' . $suffix . '@example.test', 'phone' => '0600' . random_int(200000, 299999), 'city' => 'Bléré'];

$createOrder = static function (array $customer, int $productId): WC_Order {
    $order = wc_create_order(['status' => 'processing']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name($customer['name']);
    $order->set_billing_email($customer['email']);
    $order->set_billing_phone($customer['phone']);
    $order->set_billing_city($customer['city']);
    $order->set_payment_method('cod');
    $order->add_product(wc_get_product($productId), 1);
    $order->calculate_totals();
    $order->save();

    return $order;
};

try {
    // 1. Garde du point d’entrée unique — décision pure, indépendante de WordPress.
    $assert('La création HPOS native est reconnue comme à rediriger', luziapi_is_native_order_creation_screen('admin.php', 'wc-orders', 'new', ''));
    $assert('La création legacy native est reconnue comme à rediriger', luziapi_is_native_order_creation_screen('post-new.php', '', '', 'shop_order'));
    $assert('L’édition d’une commande existante n’est pas redirigée', ! luziapi_is_native_order_creation_screen('admin.php', 'wc-orders', 'edit', ''));
    $assert('La liste des commandes n’est pas redirigée', ! luziapi_is_native_order_creation_screen('admin.php', 'wc-orders', '', ''));
    $assert('La liste legacy des commandes n’est pas redirigée', ! luziapi_is_native_order_creation_screen('edit.php', '', '', 'shop_order'));
    $quickSaleUrl = luziapi_quick_sale_url();
    $assert('La cible de redirection est bien la Vente', str_contains($quickSaleUrl, 'page=luziapi-pilotage') && str_contains($quickSaleUrl, 'tab=quick-sale'));

    // 2. Données réelles : deux clients invités distincts.
    $product = new WC_Product_Simple();
    $product->set_name('E2E — Miel Vente (ne pas commander)');
    $product->set_status('draft');
    $product->set_regular_price('9');
    $productId = (int) $product->save();

    $aliceOrder = $createOrder($alice, $productId);
    $bobOrder = $createOrder($bob, $productId);
    $createdOrderIds = [$aliceOrder->get_id(), $bobOrder->get_id()];

    // Résolution de l’identifiant de fiche d’Alice via le vrai répertoire.
    $directory = $customerHandler->handle(new GetCustomerDirectoryQuery('', 1, 100));
    $aliceProfile = null;
    foreach ($directory->customers as $profile) {
        if ($profile instanceof CustomerProfile && in_array($alice['email'], $profile->emails, true)) {
            $aliceProfile = $profile;
            break;
        }
    }
    $assert('Le client invité apparaît dans le répertoire', $aliceProfile instanceof CustomerProfile);
    $assert('Le contact principal du répertoire est bien celui de la commande', $aliceProfile?->primaryEmail() === $alice['email'] && $aliceProfile?->primaryPhone() === $alice['phone']);

    if ($aliceProfile instanceof CustomerProfile) {
        // 3. Rendu réel de la Vente préremplie pour ce client.
        $prefilled = $render(['customer' => $aliceProfile->id]);
        // Twig échappe les attributs en entités numériques (« @ » → « &#x40; ») :
        // on compare les coordonnées sur une copie décodée du HTML.
        $decoded = html_entity_decode($prefilled, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $assert('Le sélecteur de client est rendu', str_contains($prefilled, 'data-client-picker'));
        $assert('Le bandeau « Vente pour » est affiché', str_contains($prefilled, 'Vente pour'));
        $assert('L’e-mail du client est prérempli', str_contains($decoded, 'data-quick-email value="' . $alice['email'] . '"'));
        $assert('Le téléphone du client est prérempli', str_contains($decoded, 'name="phone" autocomplete="tel" value="' . $alice['phone'] . '"'));
        $assert('Le client sélectionné est présélectionné dans la liste', 1 === preg_match('/value="' . preg_quote($aliceProfile->id, '/') . '"[^>]* selected/', $prefilled));
        $assert('L’adresse e-mail du client n’est jamais placée dans une URL de la page', ! str_contains($prefilled, rawurlencode($alice['email'])));

        // Les deux clients restent proposables (l’admin voit tout le carnet).
        $assert('Les deux clients sont proposés dans le sélecteur', str_contains($decoded, 'value="' . $aliceProfile->id . '"') && str_contains($decoded, $bob['email']));
    }

    // 4. Sans client sélectionné : aucun préremplissage, aucun bandeau.
    $blank = $render([]);
    $assert('Sans client, le champ e-mail n’est pas prérempli', str_contains($blank, 'data-quick-email value=""'));
    $assert('Sans client, aucun bandeau « Vente pour »', ! str_contains($blank, 'Vente pour'));
} catch (Throwable $exception) {
    $assert('Le scénario d’intégration se termine sans exception', false, $exception->getMessage());
} finally {
    remove_filter('user_has_cap', $grantOrdersCap);
    foreach ($createdOrderIds as $orderId) {
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

WP_CLI::success(sprintf('%d assertions de la Vente validées ; données E2E supprimées.', count($results)));
