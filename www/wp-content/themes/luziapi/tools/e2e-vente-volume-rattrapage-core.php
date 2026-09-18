<?php

/**
 * Cœur partagé du test e2e « Rattrapage de la remise de volume » (bouton de la
 * fiche commande + audit lecture seule).
 *
 * Inclus par le wrapper local (WP-CLI, `make e2e-vente-volume-rattrapage-local`) et
 * par le wrapper prod à jeton (`scripts/e2e-vente-volume-rattrapage-prod.sh`). Ce
 * fichier ne fait que DÉFINIR la fonction ; il n'exécute rien à l'inclusion.
 *
 * Il pilote le VRAI chemin admin : contexte administrateur + nonce + `$_POST`, puis
 * appel de `luziapi_save_admin_order_workflow()` (le handler réellement branché sur
 * `woocommerce_process_shop_order_meta`), qui émet `luziapi_fix_volume_discount` —
 * l'abonné branché du thème applique alors le fee manquant à la commande et corrige
 * la recette au registre. Reproduit le bug prod (commande Vente à 2 miels créée
 * sans remise) : 52 € affiché → 47 € réellement encaissé. On vérifie aussi
 * l'idempotence (rejouer ne fait rien) et l'audit lecture seule (listée avant,
 * plus après). C'est la couverture que l'unitaire ne peut pas donner.
 *
 * Sûr en prod : produits masqués + commande de test isolés, statut posé via
 * set_status (donc AUCUN e-mail, AUCUNE recette auto), recette de départ seedée par
 * le dépôt NON audité, `pre_wp_mail` coupé par ceinture, et tout (commande, recettes,
 * produits) est supprimé en `finally`.
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderVolumeDiscountWriter;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

if (! function_exists('luziapi_e2e_vente_volume_rattrapage_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_vente_volume_rattrapage_run(): array
    {
        add_filter('pre_wp_mail', '__return_false', 999);

        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        global $wpdb;
        $clock = new WordPressClock();
        $pilotageSchema = new PilotageSchemaManager($wpdb);
        $pilotageSchema->migrate();
        // Dépôt NON audité : sert au SEED de la recette de départ et à la LECTURE du
        // net ; la correction, elle, passe par l'abonné branché (dépôt audité).
        $receipts = new WordPressReceiptRepository($wpdb, $pilotageSchema, $clock->timezone());
        $recordReceipt = new RecordReceiptHandler($receipts, $clock);

        $cents = static fn ($value): int => (int) round((float) $value * 100);
        $orderNet = static fn (int $orderId): int => $receipts->netTotalsByOrderIds([$orderId])[$orderId] ?? 0;
        $volumeFeeCents = static function (int $orderId) use ($cents): int {
            $sum = 0;
            foreach ((wc_get_order($orderId))->get_fees() as $fee) {
                if (str_contains((string) $fee->get_name(), 'par pot')) {
                    $sum += $cents($fee->get_total());
                }
            }

            return $sum;
        };
        $auditLists = static function (int $orderId): bool {
            // En prod, l'audit-core est déjà inclus par le wrapper sous son nom `_…`.
            if (! function_exists('luziapi_audit_vente_volume_data')) {
                require_once __DIR__ . '/audit-vente-volume-core.php';
            }
            foreach (luziapi_audit_vente_volume_data(null)['missing'] as $row) {
                if ((int) $row['id'] === $orderId) {
                    return true;
                }
            }

            return false;
        };

        $productIds = [];
        $orderId = 0;

        $grantCap = static function (array $allcaps): array {
            $allcaps['edit_shop_orders'] = true;

            return $allcaps;
        };

        try {
            $adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if ([] === $adminIds) {
                throw new \RuntimeException('Aucun administrateur pour simuler la sauvegarde de la fiche commande.');
            }
            wp_set_current_user((int) $adminIds[0]);
            add_filter('user_has_cap', $grantCap);

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
            // Deux miels distincts, comme le cas prod : 3 × 10 € + 2 × 11 € = 52 €.
            $printemps = $makeProduct('E2E — Miel Printemps rattrapage (test, à supprimer)', '10');
            $tournesol = $makeProduct('E2E — Miel Tournesol rattrapage (test, à supprimer)', '11');

            // Commande Vente créée SANS remise de volume (état d'avant le correctif).
            $order = wc_create_order(['status' => 'pending']);
            $order->set_billing_first_name('E2E Rattrapage');
            $order->set_billing_email('e2e-rattrapage-' . bin2hex(random_bytes(5)) . '@example.test');
            $order->set_billing_phone('0600000000');
            $order->add_product(wc_get_product($printemps), 3);
            $order->add_product(wc_get_product($tournesol), 2);
            $order->update_meta_data('_luziapi_quick_sale', 'yes');
            $order->set_payment_method('cod');
            $order->calculate_totals();
            $order->set_status('completed'); // set_status : ne déclenche PAS les hooks.
            $order->save();
            $orderId = (int) $order->get_id();

            $assert('Base : commande à 52 € (5 pots, sans remise)', 5_200 === $cents($order->get_total()), 'total=' . $cents($order->get_total()));
            $assert('Base : 5 pots payés décomptés', 5 === WooCommerceOrderVolumeDiscountWriter::paidJars(wc_get_order($orderId)));
            $assert('Base : 5 € de remise manquante', 500 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($orderId)));

            // Recette de départ : la commande a été encaissée à 52 € (le tort à corriger).
            $recordReceipt->handle(new RecordReceiptCommand(
                $orderId,
                $clock->now(),
                5_200,
                'cash',
                ReceiptEntryType::Collection,
                'Encaissement E2E rattrapage',
                (int) $adminIds[0],
            ));
            $assert('Base : recette de départ = 52 €', 5_200 === $orderNet($orderId), 'net=' . $orderNet($orderId));

            // Câblage réel.
            $assert(
                'Câblage : save handler branché (woocommerce_process_shop_order_meta, prio 20)',
                20 === has_action('woocommerce_process_shop_order_meta', 'luziapi_save_admin_order_workflow'),
            );
            $assert(
                'Câblage : un abonné écoute luziapi_fix_volume_discount',
                false !== has_action('luziapi_fix_volume_discount'),
            );

            // Audit : la commande figure bien dans la liste à rattraper.
            $assert('Audit : commande listée avant rattrapage', $auditLists($orderId));

            $nonce = wp_create_nonce('luziapi_save_order_workflow');

            // 1) Rattrapage via le VRAI chemin admin (case cochée).
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_fix_volume_discount'  => 'yes',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));

            $assert('Rattrapage : fee de volume −5 € présent', -500 === $volumeFeeCents($orderId), 'fee=' . $volumeFeeCents($orderId));
            $assert('Rattrapage : commande ramenée à 47 €', 4_700 === $cents(wc_get_order($orderId)->get_total()), 'total=' . $cents(wc_get_order($orderId)->get_total()));
            $assert('Rattrapage : recette corrigée à 47 €', 4_700 === $orderNet($orderId), 'net=' . $orderNet($orderId));
            $assert('Rattrapage : plus de remise manquante', 0 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($orderId)));

            // 2) Idempotence : rejouer le rattrapage ne change plus rien.
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_fix_volume_discount'  => 'yes',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert('Idempotence : fee toujours unique −5 €', -500 === $volumeFeeCents($orderId), 'fee=' . $volumeFeeCents($orderId));
            $assert('Idempotence : recette toujours 47 € (pas de double contre-passe)', 4_700 === $orderNet($orderId), 'net=' . $orderNet($orderId));

            // 3) Audit : la commande corrigée n'est plus listée.
            $assert('Audit : commande absente après rattrapage', ! $auditLists($orderId));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('user_has_cap', $grantCap);
            $_POST = [];
            if ($orderId > 0) {
                $wpdb->delete($pilotageSchema->tableName(), ['order_id' => $orderId], ['%d']);
                $order = wc_get_order($orderId);
                if ($order instanceof WC_Order) {
                    $order->delete(true);
                }
            }
            foreach ($productIds as $pid) {
                wp_delete_post($pid, true);
            }
            $left = $orderId > 0
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $orderId))
                : 0;
            $cleanup = 0 === $left ? 'ok (aucune recette résiduelle)' : ($left . ' recette(s) résiduelle(s) !');
            $assert('Nettoyage : aucune recette résiduelle', 0 === $left);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
