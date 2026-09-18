<?php

/**
 * Test d'intégration local de la REMISE DE VOLUME (−1 € par pot dès 2 pots) dans la
 * Vente du pilotage — régression du bug prod : plusieurs miels différents ne
 * recevaient plus la remise (le fee de panier ne se déclenche pas sur une commande
 * admin ; la Vente ne l'appliquait pas).
 *
 * Exécution : make e2e-vente-volume-local
 *
 * Contre le vrai `WooCommerceQuickSaleOrderWriter`, avec DEUX miels distincts (3 + 2),
 * vérifie que la commande porte bien une remise de −5 € (total 47 € pour 52 € de pots),
 * et qu'un seul pot ne déclenche aucune remise. Tout est nettoyé, même en cas d'échec.
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLine;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceQuickSaleOrderWriter;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_create_order')) {
    WP_CLI::error('WooCommerce doit être actif pour lancer le test de remise volume.');
}

add_filter('pre_wp_mail', '__return_false', 999);

global $wpdb;
$schema = new LoyaltySchemaManager($wpdb);
$schema->migrate();
$cents = static fn ($value): int => (int) round((float) $value * 100);

$results = [];
$assert = static function (string $label, bool $success, string $detail = '') use (&$results): void {
    $results[] = ['label' => $label, 'success' => $success, 'detail' => $detail];
};

$productIds = [];
$orderIds = [];
$suffix = strtolower(wp_generate_password(10, false, false));
$email = 'vente-volume-' . $suffix . '@example.test';
$phone = '0699' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$customerKey = LoyaltyIdentity::fromContact($email, $phone)?->key ?? '';
$phoneKey = LoyaltyIdentity::contactKeys('', $phone)['phone'] ?? '';

try {
    $makeProduct = static function (string $name, string $price) use (&$productIds): int {
        $p = new WC_Product_Simple();
        $p->set_name($name);
        $p->set_status('publish');
        $p->set_catalog_visibility('hidden');
        $p->set_regular_price($price);
        $p->set_price($price);
        $p->set_manage_stock(true);
        $p->set_stock_quantity(50);
        $id = (int) $p->save();
        $productIds[] = $id;

        return $id;
    };
    $printemps = $makeProduct('E2E — Miel Printemps volume (ne pas commander)', '10');
    $tournesol = $makeProduct('E2E — Miel Tournesol volume (ne pas commander)', '11');

    $makeSale = static function (array $lines) use ($email, $phone): CreateQuickSaleCommand {
        return new CreateQuickSaleCommand(
            $lines,
            'Client Volume E2E',
            $email,
            $phone,
            '',
            '',
            '',
            'market',
            'cash',
            'immediate',
            true,
            false,
            new DateTimeImmutable('now', wp_timezone()),
            0,
            wp_generate_uuid4(),
        );
    };

    // Cas prod : 2 miels différents, 3 + 2 = 5 pots payés (30 € + 22 € = 52 €).
    $created = (new WooCommerceQuickSaleOrderWriter())->create($makeSale([
        new QuickSaleLine($printemps, 3),
        new QuickSaleLine($tournesol, 2),
    ]));
    $orderIds[] = $created->orderId;
    $order = wc_get_order($created->orderId);

    $volumeFee = 0;
    foreach ($order->get_fees() as $fee) {
        if (str_contains((string) $fee->get_name(), 'par pot')) {
            $volumeFee = $cents($fee->get_total());
        }
    }
    $assert('Multi-miels : une remise de volume est présente', 0 !== $volumeFee);
    $assert('Multi-miels : remise = −5 € (5 pots)', -500 === $volumeFee, 'fee=' . $volumeFee);
    $assert('Multi-miels : total encaissé remisé = 47 € (52 − 5)', 4_700 === $created->totalCents, 'total=' . $created->totalCents);

    // Contrôle : un seul pot ne déclenche aucune remise.
    $single = (new WooCommerceQuickSaleOrderWriter())->create($makeSale([new QuickSaleLine($printemps, 1)]));
    $orderIds[] = $single->orderId;
    $hasFee = false;
    foreach ((wc_get_order($single->orderId))->get_fees() as $fee) {
        if (str_contains((string) $fee->get_name(), 'par pot')) {
            $hasFee = true;
        }
    }
    $assert('Un seul pot : aucune remise de volume', ! $hasFee);
    $assert('Un seul pot : total plein = 10 €', 1_000 === $single->totalCents, 'total=' . $single->totalCents);
} catch (Throwable $exception) {
    $assert('Le scénario de remise volume se termine sans exception', false, $exception->getMessage());
} finally {
    foreach ([$customerKey, $phoneKey] as $key) {
        if ('' !== $key) {
            $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $key], ['%s']);
            $wpdb->delete($schema->identityLinksTableName(), ['identity_key' => $key], ['%s']);
        }
    }
    foreach ($orderIds as $orderId) {
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

WP_CLI::success(sprintf('%d assertions de remise volume validées ; données E2E supprimées.', count($results)));
