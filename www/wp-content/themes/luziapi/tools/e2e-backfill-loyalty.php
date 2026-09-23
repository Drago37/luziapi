<?php

/**
 * Test d'intégration local du BACKFILL de fidélité (rétro-crédit des commandes déjà
 * « Terminée »).
 *
 * Exécution : make e2e-backfill-loyalty-local
 *
 * Crée une commande de test « Terminée » (datée dans le passé) NON encore créditée,
 * puis exerce `luziapi_backfill_loyalty()` scopé à cette seule commande. Vérifie :
 * la simulation n'écrit rien, le rétro-crédit crédite exactement les pots payés
 * (offerts et non-admissibles exclus), l'écriture est datée à la date de complétion
 * réelle, et un second passage est idempotent (aucun doublon). Rien n'est laissé en
 * base, même en cas d'échec.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyCommand;
use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Shared\Infrastructure\WordPress\WordPressClock;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressIdGenerator;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyIdentityLinks;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}

if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de backfill.');
}

require_once __DIR__ . '/backfill-loyalty.php';

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;

$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
$counter = new WooCommerceEligiblePotCounter();

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productIds = [];
$orderId = 0;
$customerKey = '';
$phoneKey = '';
$completedOn = '2025-03-01';
$testSuffix = strtolower(wp_generate_password(10, false, false));
$customerEmail = 'backfill-' . $testSuffix . '@example.test';
// Téléphone aléatoire (vrai mobile FR) : hermétique entre exécutions, sinon un lien
// d'identité résiduel sur un numéro fixe ferait refuser l'auto-liaison prudente.
$customerPhone = '0699' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    $pot = new WC_Product_Simple();
    $pot->set_name('E2E — Pot backfill (ne pas commander)');
    $pot->set_status('publish');
    $pot->set_catalog_visibility('hidden');
    $pot->set_regular_price('12');
    $pot->set_price('12');
    $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
    $productId = (int) $pot->save();

    $other = new WC_Product_Simple();
    $other->set_name('E2E — Coffret hors fidélité backfill (ne pas commander)');
    $other->set_status('publish');
    $other->set_catalog_visibility('hidden');
    $other->set_regular_price('30');
    $other->set_price('30');
    $other->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'no');
    $otherId = (int) $other->save();
    $productIds = [$productId, $otherId];

    $order = wc_create_order(['status' => 'pending']);
    if (! $order instanceof WC_Order) {
        throw new RuntimeException('Impossible de créer la commande E2E.');
    }
    $order->set_billing_first_name('Client');
    $order->set_billing_email($customerEmail);
    $order->set_billing_phone($customerPhone);
    $order->set_payment_method('cod');
    $order->add_product(wc_get_product($productId), 4);   // 4 pots payés
    $order->add_product(wc_get_product($otherId), 5);     // hors programme
    $gift = new WC_Order_Item_Product();
    $gift->set_product(wc_get_product($productId));
    $gift->set_quantity(1);
    $gift->set_subtotal('0');
    $gift->set_total('0');
    $gift->add_meta_data(WooCommerceEligiblePotCounter::OFFERT_LINE_META, 'yes', true);
    $order->add_item($gift);
    $order->calculate_totals();
    // Commande déjà « Terminée » et datée dans le passé, comme une vraie commande
    // historique jamais passée par le moteur de fidélité.
    $order->set_status('completed');
    $order->set_date_completed(strtotime($completedOn . ' 10:00:00'));
    $order->save();
    $orderId = (int) $order->get_id();

    $customerKey = LoyaltyIdentity::fromContact($customerEmail, $customerPhone)?->key ?? '';
    $phoneKey = LoyaltyIdentity::keysForContact('', $customerPhone)[0] ?? '';
    $assert('L\'identité fidélité de la commande est résolue', '' !== $customerKey);

    // Passer le statut à « Terminée » a pu déclencher le subscriber live (crédit par
    // réconciliation) : on vide le journal de cette commande pour simuler une vraie
    // commande HISTORIQUE, jamais vue par le moteur de fidélité.
    $wpdb->delete($schema->ledgerTableName(), ['source_order_id' => $orderId], ['%d']);
    $assert('La commande n\'est pas encore au journal (historique simulée)', ! $ledger->hasEntryForOrder($orderId));
    $assert('Le compteur ne retient que les 4 pots payés (offert + coffret exclus)', 4 === $counter->countEligiblePots(wc_get_order($orderId)));

    // Simulation : ne doit rien écrire.
    $dry = luziapi_backfill_loyalty(true, [$orderId]);
    $assert('Simulation : la commande est vue (1)', 1 === $dry['orders'], 'orders=' . $dry['orders']);
    $assert('Simulation : 4 pots seraient crédités', 4 === $dry['pots'], 'pots=' . $dry['pots']);
    $assert('Simulation : rien n\'est réellement crédité', 0 === $dry['credited']);
    $assert('Simulation : le journal reste vide pour la commande', ! $ledger->hasEntryForOrder($orderId));

    // Rétro-crédit réel.
    $run = luziapi_backfill_loyalty(false, [$orderId]);
    $assert('Rétro-crédit : 1 commande créditée', 1 === $run['credited'], 'credited=' . $run['credited']);
    $assert('Rétro-crédit : 4 pots crédités', 4 === $run['pots'], 'pots=' . $run['pots']);
    $assert('Le journal de la commande porte 4 pots', 4 === $ledger->orderTotals($orderId)['pots']);

    // Le backfill sème aussi les liens d'identité (e-mail ↔ téléphone) rétroactivement.
    $linksReader = new WordPressLoyaltyIdentityLinks($wpdb, $schema);
    $assert('Backfill : lien d\'identité semé (e-mail ↔ téléphone)', in_array($phoneKey, $linksReader->expand([$customerKey]), true));

    $storedDate = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT occurred_at FROM ' . $schema->ledgerTableName() . ' WHERE idempotency_key = %s',
        'credit:' . $orderId
    ));
    $assert('L\'écriture est datée à la complétion réelle (' . $completedOn . ')', str_starts_with($storedDate, $completedOn), 'occurred_at=' . $storedDate);

    // Idempotence : un second passage ne double rien.
    $entriesBefore = $ledger->totalsForCustomerKeys([$customerKey])['entryCount'];
    $again = luziapi_backfill_loyalty(false, [$orderId]);
    $assert('Second passage : aucune nouvelle écriture', 0 === $again['credited'] && 1 === $again['already'], 'credited=' . $again['credited'] . ' already=' . $again['already']);
    $assert('Second passage : le nombre d\'écritures est inchangé', $entriesBefore === $ledger->totalsForCustomerKeys([$customerKey])['entryCount']);
    $assert('Le total net du client reste 4 pots', 4 === $ledger->totalsForCustomerKeys([$customerKey])['pots']);

    // Non-régression du double comptage : une commande déjà créditée par le moteur
    // live (réconciliation, clés `reconcile-*`) ne doit PAS être recréditée par le
    // backfill (le bug corrigé : la détection ne se limite plus à la clé `credit:`).
    $wpdb->delete($schema->ledgerTableName(), ['source_order_id' => $orderId], ['%d']);
    $reconcile = new ReconcileOrderLoyaltyHandler($ledger, new WordPressClock(), new WordPressIdGenerator());
    $reconcile->handle(new ReconcileOrderLoyaltyCommand($orderId, $customerKey, 4, 0));
    $assert('Pré-crédit par réconciliation (clés reconcile-*) : 4 pots', 4 === $ledger->orderTotals($orderId)['pots']);
    $reconciled = luziapi_backfill_loyalty(false, [$orderId]);
    $assert('Backfill : commande déjà créditée par réconciliation ignorée', 0 === $reconciled['credited'] && 1 === $reconciled['already'], 'credited=' . $reconciled['credited'] . ' already=' . $reconciled['already']);
    $assert('Backfill : aucun double comptage (toujours 4 pots, pas 8)', 4 === $ledger->orderTotals($orderId)['pots']);
} catch (Throwable $exception) {
    $assert('Le scénario de backfill se termine sans exception', false, $exception->getMessage());
} finally {
    if ('' !== $customerKey) {
        $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $customerKey], ['%s']);
        $wpdb->delete($schema->identityLinksTableName(), ['identity_key' => $customerKey], ['%s']);
    }
    if ('' !== $phoneKey) {
        $wpdb->delete($schema->identityLinksTableName(), ['identity_key' => $phoneKey], ['%s']);
    }
    if ($orderId > 0) {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    foreach ($productIds as $pid) {
        wp_delete_post($pid, true);
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

WP_CLI::success(sprintf('%d assertions de backfill validées ; données E2E supprimées.', count($results)));
