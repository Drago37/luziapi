<?php

/**
 * Cœur partagé du test e2e « une recette est retirée du registre quand sa commande
 * est mise à la corbeille ou supprimée ».
 *
 * Inclus par le wrapper local (WP-CLI, `make e2e-orphan-receipt-local`) et par le
 * wrapper prod à jeton (`scripts/e2e-orphan-receipt-prod.sh`). Ce fichier ne fait
 * que DÉFINIR la fonction ; il n'exécute rien à l'inclusion.
 *
 * Il vérifie le VRAI chemin : on crée une commande avec une recette au registre,
 * puis on la met à la **corbeille** (`$order->delete(false)` → `woocommerce_trash_order`)
 * et on la **supprime** (`$order->delete(true)` → `woocommerce_before_delete_order`) ;
 * l'abonné `WooCommerceOrphanReceiptSubscriber` doit avoir retiré la recette. On
 * vérifie aussi que les hooks sont câblés.
 *
 * Sûr en prod : produit masqué + commandes isolés, statut posé via set_status (aucun
 * e-mail, aucune fidélité), `pre_wp_mail` coupé par ceinture, tout nettoyé en `finally`.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Shared\Money;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

if (! function_exists('luziapi_e2e_orphan_receipt_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_orphan_receipt_run(): array
    {
        add_filter('pre_wp_mail', '__return_false', 999);

        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        global $wpdb;
        $schema = new PilotageSchemaManager($wpdb);
        $receipts = new WordPressReceiptRepository($wpdb, $schema, wp_timezone());
        $table = $schema->tableName();

        $countFor = static fn (int $orderId): int => (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $orderId)
        );
        $addReceipt = static function (int $orderId) use ($receipts): void {
            $receipts->add(new NewReceiptEntry(
                $orderId,
                new DateTimeImmutable('now', wp_timezone()),
                new Money(1500),
                'cash',
                ReceiptEntryType::Collection,
                'E2E — recette de test',
                null,
                0,
                new DateTimeImmutable('now', wp_timezone()),
            ));
        };

        $productId = 0;
        $trashedId = 0;
        $deletedId = 0;

        // Commande volontairement NON « Terminée » : le retrait de la recette à la
        // corbeille/suppression est indépendant du statut, et on évite ainsi que
        // l'auto-encaissement ajoute une seconde recette (le compte doit rester à 1).
        $makeOrder = static function (int $productId): int {
            $order = wc_create_order(['status' => 'pending']);
            $order->set_billing_first_name('E2E');
            $order->add_product(wc_get_product($productId), 1);
            $order->calculate_totals();
            $order->save();

            return (int) $order->get_id();
        };

        try {
            $assert(
                'Câblage : abonné sur woocommerce_trash_order',
                false !== has_action('woocommerce_trash_order'),
            );
            $assert(
                'Câblage : abonné sur woocommerce_before_delete_order',
                false !== has_action('woocommerce_before_delete_order'),
            );

            $pot = new WC_Product_Simple();
            $pot->set_name('E2E — recette orpheline (test, à supprimer)');
            $pot->set_status('publish');
            $pot->set_catalog_visibility('hidden');
            $pot->set_regular_price('15');
            $pot->set_price('15');
            $productId = (int) $pot->save();

            // 1) Corbeille → la recette doit disparaître.
            $trashedId = $makeOrder($productId);
            $addReceipt($trashedId);
            $assert('Corbeille : recette présente au départ', 1 === $countFor($trashedId));
            wc_get_order($trashedId)->delete(false); // corbeille
            $assert('Corbeille : recette retirée', 0 === $countFor($trashedId));

            // 2) Suppression définitive → la recette doit disparaître.
            $deletedId = $makeOrder($productId);
            $addReceipt($deletedId);
            $assert('Suppression : recette présente au départ', 1 === $countFor($deletedId));
            wc_get_order($deletedId)->delete(true); // suppression définitive
            $assert('Suppression : recette retirée', 0 === $countFor($deletedId));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            $left = 0;
            foreach ([$trashedId, $deletedId] as $orderId) {
                if ($orderId > 0) {
                    $left += $countFor($orderId);
                    $wpdb->delete($table, ['order_id' => $orderId], ['%d']);
                    $order = wc_get_order($orderId);
                    if ($order instanceof WC_Order) {
                        $order->delete(true);
                    }
                }
            }
            if ($productId > 0) {
                wp_delete_post($productId, true);
            }
            $cleanup = 0 === $left ? 'ok (aucune recette résiduelle)' : ($left . ' recette(s) résiduelle(s) !');
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
