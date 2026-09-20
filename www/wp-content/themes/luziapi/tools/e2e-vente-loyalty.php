<?php

/**
 * Test d'intégration local du chemin RÉEL de la Vente avec fidélité et remise.
 *
 * Exécution : make e2e-vente-loyalty-local
 *
 * Contrairement à e2e-loyalty (qui construit les lignes à la main), ce test pilote
 * le vrai écrivain de la Vente `WooCommerceQuickSaleOrderWriter::create()` avec une
 * commande contenant : des pots payés, un pot offert (geste), un pot offert au
 * titre de la fidélité et une remise remerciement. Il vérifie la structure de la
 * commande créée (lignes marquées, 0 €, remise en discount_total), le décompte du
 * stock, puis — la commande passant « Terminée » — le crédit des pots et la
 * consommation de l'avantage écrits par le subscriber branché du thème.
 * Nettoie toutes les données créées.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLine;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer ce test.');
}

$sentEmails = [];
// Capture les e-mails (sujet + corps) au lieu de les envoyer : on vérifie QUEL
// e-mail la Vente déclenche (le LuziApi « Terminée » avec fidélité, pas le standard).
add_filter('pre_wp_mail', static function ($short, $atts) use (&$sentEmails) {
    $sentEmails[] = is_array($atts) ? $atts : [];

    return false;
}, 5, 2);
add_filter('pre_wp_mail', '__return_false', 999);
$previousDecimals = get_option('woocommerce_price_num_decimals');
update_option('woocommerce_price_num_decimals', '2');

global $wpdb;
$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$cents = static fn ($value): int => (int) round((float) $value * 100);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productId = 0;
$product2Id = 0;
$orderId = 0;
$customerKey = '';
$suffix = strtolower(wp_generate_password(10, false, false));
$email = 'vente-loyalty-' . $suffix . '@example.test';
$phone = '0600000000';

try {
    // L'écrivain de la Vente exige un produit achetable (is_purchasable) : publié
    // mais masqué du catalogue, et supprimé en fin de test.
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot Vente fidélité (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->set_manage_stock(true);
    $pot->set_stock_quantity(20);
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    // Second miel, pour offrir DEUX miels différents au titre de la fidélité en une
    // seule vente.
    $pot2 = new WC_Product_Simple();
    $pot2->set_name('E2E — Pot Vente fidélité #2 (ne pas commander)');
    $pot2->set_status('publish');
    $pot2->set_catalog_visibility('hidden');
    $pot2->set_regular_price('12');
    $pot2->set_price('12');
    $pot2->set_manage_stock(true);
    $pot2->set_stock_quantity(20);
    $pot2->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $product2Id = (int) $pot2->save();

    $command = new CreateQuickSaleCommand(
        [new QuickSaleLine($productId, 2)],            // 2 pots payés (24,00 €)
        'Client Vente E2E',
        $email,
        $phone,
        '',
        '',
        '',
        'market',
        'cash',
        'immediate',
        true,   // payée -> passe « Terminée »
        true,   // envoyer l'e-mail client (comme le flux classique)
        new DateTimeImmutable('now', wp_timezone()),
        0,
        wp_generate_uuid4(),
        [new QuickSaleLine($productId, 1)],           // 1 pot offert (geste)
        [new QuickSaleLine($productId, 1), new QuickSaleLine($product2Id, 1)], // 2 miels DIFFÉRENTS en fidélité
        ThankYouDiscount::percent(10),                // -10 % sur les pots payés
    );

    // L'e-mail custom « Terminée » doit exister et être activé (même instance que la
    // Vente récupérera via WC()->mailer()) pour que l'envoi capturé soit déterministe.
    $mailerEmails = WC()->mailer()->get_emails();
    $completedEmail = $mailerEmails['Luziapi_Email_Customer_Completed'] ?? null;
    $assert('Câblage : l\'e-mail LuziApi « Terminée » est enregistré', $completedEmail instanceof \Luziapi_Order_Status_Email);
    if ($completedEmail instanceof \Luziapi_Order_Status_Email) {
        $completedEmail->enabled = 'yes';
    }

    $created = (new WooCommerceQuickSaleOrderWriter())->create($command);
    $orderId = $created->orderId;
    $customerKey = LoyaltyIdentity::fromContact($email, $phone)?->key ?? '';

    $order = wc_get_order($orderId);
    $paid = 0;
    $gift = 0;
    $reward = 0;
    $rewardProductIds = [];
    foreach ($order->get_items() as $item) {
        $lineCents = $cents($item->get_total());
        $isOffered = 'yes' === (string) $item->get_meta(WooCommerceEligiblePotCounter::OFFERT_LINE_META);
        $isReward = 'yes' === (string) $item->get_meta(WooCommerceEligiblePotCounter::REWARD_LINE_META);
        if ($isReward) {
            ++$reward;
            $rewardProductIds[] = $item instanceof WC_Order_Item_Product ? (int) $item->get_product_id() : 0;
            $assert('La ligne fidélité est à 0 € et marquée offerte', 0 === $lineCents && $isOffered);
        } elseif ($isOffered) {
            ++$gift;
            $assert('La ligne offerte (geste) est à 0 €', 0 === $lineCents);
        } else {
            ++$paid;
            $assert('La ligne payée porte la remise (21,60 € pour 2 pots)', 2_160 === $lineCents);
        }
    }
    $assert('La commande a bien 4 lignes (payée, offerte, 2 fidélité)', 1 === $paid && 1 === $gift && 2 === $reward);
    $assert('Les 2 pots fidélité sont sur 2 miels DIFFÉRENTS', 2 === count(array_unique($rewardProductIds)) && in_array($productId, $rewardProductIds, true) && in_array($product2Id, $rewardProductIds, true));
    // 2 pots payés à 12 € = 24 € ; remise remerciement −10 % (discount_total 2,40 € → ligne 21,60 €)
    // PUIS remise de volume −1 €/pot × 2 = −2 € (fee), soit un total encaissé de 19,60 €.
    $volumeFee = 0;
    foreach ($order->get_fees() as $fee) {
        if (str_contains((string) $fee->get_name(), 'par pot')) {
            $volumeFee = $cents($fee->get_total());
        }
    }
    $assert('La remise de volume (−2 €) est appliquée', -200 === $volumeFee, 'fee=' . $volumeFee);
    $assert('Le total encaissé cumule remerciement + volume (19,60 €)', 1_960 === $created->totalCents, 'total=' . $created->totalCents);
    $assert('La remise remerciement apparaît en discount_total (2,40 €)', 240 === $cents($order->get_discount_total()));
    $assert('La commande est « Terminée »', 'completed' === $order->get_status());
    // Miel #1 : 2 payés + 1 geste + 1 fidélité = 4 pots (20 → 16). Miel #2 : 1 fidélité (20 → 19).
    $assert('Stock miel #1 décompté des 4 pots (20 → 16)', 16 === (int) wc_get_product($productId)->get_stock_quantity());
    $assert('Stock miel #2 décompté du pot fidélité (20 → 19)', 19 === (int) wc_get_product($product2Id)->get_stock_quantity());

    // La commande étant passée « Terminée », le subscriber branché a crédité les
    // pots payés (offerts exclus) et consommé les avantages (2 pots fidélité).
    $orderTotals = $ledger->orderTotals($orderId);
    $assert('Le subscriber a crédité les 2 pots payés', 2 === $orderTotals['pots']);
    $assert('Le subscriber a consommé 2 avantages (2 miels offerts)', -2 === $orderTotals['rights']);

    // E-mail : la Vente doit envoyer le MÊME e-mail que le flux classique « Terminée »
    // (sujet LuziApi + récap fidélité), pas l'e-mail WooCommerce standard.
    $subjects = implode(' || ', array_map(static fn (array $m): string => (string) ($m['subject'] ?? ''), $sentEmails));
    $bodies = implode(' || ', array_map(static fn (array $m): string => (string) ($m['message'] ?? ''), $sentEmails));
    $assert('E-mail : un e-mail client a été envoyé', [] !== $sentEmails, 'count=' . count($sentEmails));
    $assert('E-mail : c\'est la confirmation LuziApi « Terminée » (pas le standard WooCommerce)', str_contains($subjects, 'a bien été remise'), 'sujets=' . $subjects);
    $assert('E-mail : il porte le récap fidélité', str_contains($bodies, 'fidélité'), 'fidélité absente du corps');
} catch (Throwable $exception) {
    $assert('Le scénario se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
    }
    if ($orderId > 0) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    foreach ([$productId, $product2Id] as $pid) {
        if ($pid > 0) {
            wp_delete_post($pid, true);
        }
    }
    update_option('woocommerce_price_num_decimals', false === $previousDecimals ? '2' : $previousDecimals);
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

WP_CLI::success(sprintf('%d assertions Vente+fidélité validées ; données E2E supprimées.', count($results)));
