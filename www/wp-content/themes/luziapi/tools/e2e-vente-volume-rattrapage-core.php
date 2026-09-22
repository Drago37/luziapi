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

use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use LuziApi\Shop\Domain\Sales\VolumeDiscount;
use LuziApi\Shop\Infrastructure\WooCommerce\OfferedOrderItem;
use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderVolumeDiscountWriter;
use LuziApi\Shop\Infrastructure\WordPress\ShopSchemaManager;
use LuziApi\Shared\Infrastructure\WordPress\WordPressClock;
use LuziApi\Shop\Infrastructure\WordPress\WordPressReceiptRepository;

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
        $pilotageSchema = new ShopSchemaManager($wpdb);
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
        $orderIds = [];

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

            // Fabrique une commande Vente à 5 pots PAYÉS (52 €), décorée avant calcul
            // (lignes offertes, fee de remise partiel…), puis figée au statut voulu
            // (set_status ne déclenche PAS les hooks).
            $makeSaleOrder = static function (string $status, callable $decorate) use (&$orderIds, $printemps, $tournesol): int {
                $order = wc_create_order(['status' => 'pending']);
                $order->set_billing_first_name('E2E Rattrapage');
                $order->set_billing_email('e2e-rattrapage-' . bin2hex(random_bytes(5)) . '@example.test');
                $order->set_billing_phone('0600000000');
                $order->add_product(wc_get_product($printemps), 3);
                $order->add_product(wc_get_product($tournesol), 2);
                $order->update_meta_data('_luziapi_quick_sale', 'yes');
                $order->set_payment_method('cod');
                $decorate($order);
                $order->calculate_totals();
                $order->set_status($status);
                $order->save();
                $id = (int) $order->get_id();
                $orderIds[] = $id;

                return $id;
            };
            $nonce = wp_create_nonce('luziapi_save_order_workflow');
            $fix = static function (int $orderId) use ($nonce): void {
                $_POST = [
                    'luziapi_order_workflow_nonce' => $nonce,
                    'luziapi_fix_volume_discount'  => 'yes',
                ];
                luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
                $_POST = [];
            };

            // Câblage réel (une fois).
            $assert(
                'Câblage : save handler branché (woocommerce_process_shop_order_meta, prio 20)',
                20 === has_action('woocommerce_process_shop_order_meta', 'luziapi_save_admin_order_workflow'),
            );
            $assert(
                'Câblage : un abonné écoute luziapi_fix_volume_discount',
                false !== has_action('luziapi_fix_volume_discount'),
            );

            // ===== Scénario 1 : cas prod complet, AVEC lignes offertes/fidélité mêlées
            // (forme normale d'une commande Vente), encaissée à 52 €. =====
            $order1 = $makeSaleOrder('completed', static function (WC_Order $o) use ($printemps): void {
                OfferedOrderItem::addTo($o, wc_get_product($printemps), 1, false); // geste
                OfferedOrderItem::addTo($o, wc_get_product($printemps), 1, true);  // fidélité
            });
            $assert('S1 : total à 52 € (offerts à 0 €, sans remise)', 5_200 === $cents(wc_get_order($order1)->get_total()), 'total=' . $cents(wc_get_order($order1)->get_total()));
            $assert('S1 : 5 pots payés (offert + fidélité IGNORÉS)', 5 === WooCommerceOrderVolumeDiscountWriter::paidJars(wc_get_order($order1)), 'paid=' . WooCommerceOrderVolumeDiscountWriter::paidJars(wc_get_order($order1)));
            $assert('S1 : 5 € de remise manquante', 500 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($order1)));
            $recordReceipt->handle(new RecordReceiptCommand($order1, $clock->now(), 5_200, 'cash', ReceiptEntryType::Collection, 'Encaissement E2E rattrapage S1', (int) $adminIds[0]));
            $assert('S1 : recette de départ = 52 €', 5_200 === $orderNet($order1), 'net=' . $orderNet($order1));
            $assert('S1 : commande listée à l’audit avant rattrapage', $auditLists($order1));

            $fix($order1);
            $assert('S1 : fee de volume −5 € présent', -500 === $volumeFeeCents($order1), 'fee=' . $volumeFeeCents($order1));
            $assert('S1 : commande ramenée à 47 €', 4_700 === $cents(wc_get_order($order1)->get_total()), 'total=' . $cents(wc_get_order($order1)->get_total()));
            $assert('S1 : recette corrigée à 47 €', 4_700 === $orderNet($order1), 'net=' . $orderNet($order1));
            $assert('S1 : plus de remise manquante', 0 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($order1)));

            $fix($order1); // idempotence
            $assert('S1 : idempotence — fee toujours −5 €', -500 === $volumeFeeCents($order1), 'fee=' . $volumeFeeCents($order1));
            $assert('S1 : idempotence — recette toujours 47 € (pas de double contre-passe)', 4_700 === $orderNet($order1), 'net=' . $orderNet($order1));
            $assert('S1 : commande absente de l’audit après rattrapage', ! $auditLists($order1));

            // ===== Scénario 2 : remise PARTIELLE déjà présente (−2 €), encaissée à 50 € :
            // seul le delta manquant (3 €) doit être appliqué et contre-passé. =====
            $order2 = $makeSaleOrder('completed', static function (WC_Order $o): void {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name(VolumeDiscount::label(5)); // libellé « … par pot … »
                $fee->set_total('-2');
                $o->add_item($fee);
            });
            $assert('S2 : remise partielle en place (−2 €)', -200 === $volumeFeeCents($order2), 'fee=' . $volumeFeeCents($order2));
            $assert('S2 : delta manquant = 3 €', 300 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($order2)), 'missing=' . WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($order2)));
            $recordReceipt->handle(new RecordReceiptCommand($order2, $clock->now(), 5_000, 'cash', ReceiptEntryType::Collection, 'Encaissement E2E rattrapage S2', (int) $adminIds[0]));

            $fix($order2);
            $assert('S2 : remise totale −5 € après delta', -500 === $volumeFeeCents($order2), 'fee=' . $volumeFeeCents($order2));
            $assert('S2 : commande ramenée à 47 €', 4_700 === $cents(wc_get_order($order2)->get_total()), 'total=' . $cents(wc_get_order($order2)->get_total()));
            $assert('S2 : recette corrigée du seul delta (50 € → 47 €)', 4_700 === $orderNet($order2), 'net=' . $orderNet($order2));

            // ===== Scénario 3 : commande PAS encore encaissée (aucune recette) :
            // le fee est posé mais AUCUNE contre-passe ne doit être créée. =====
            $order3 = $makeSaleOrder('on-hold', static function (WC_Order $o): void {
            });
            $assert('S3 : aucune recette au départ', 0 === $orderNet($order3), 'net=' . $orderNet($order3));

            $fix($order3);
            $assert('S3 : fee de volume −5 € présent', -500 === $volumeFeeCents($order3), 'fee=' . $volumeFeeCents($order3));
            $assert('S3 : commande ramenée à 47 €', 4_700 === $cents(wc_get_order($order3)->get_total()), 'total=' . $cents(wc_get_order($order3)->get_total()));
            $assert('S3 : toujours aucune recette (pas de contre-passe à tort)', 0 === $orderNet($order3), 'net=' . $orderNet($order3));
            $receiptCount3 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $order3));
            $assert('S3 : aucune ligne de recette écrite', 0 === $receiptCount3, 'lignes=' . $receiptCount3);

            // ===== Scénario 5 : REPRISE après échec — le fee est déjà posé (rien à
            // appliquer) mais le registre est resté à 52 €. Rejouer doit RÉCONCILIER
            // la recette à 47 € (auto-réparation du cas #1). =====
            $order5 = $makeSaleOrder('completed', static function (WC_Order $o): void {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name(VolumeDiscount::label(5));
                $fee->set_total('-5'); // remise déjà complète côté commande
                $o->add_item($fee);
            });
            $assert('S5 : remise déjà complète (rien à appliquer)', 0 === WooCommerceOrderVolumeDiscountWriter::missingCents(wc_get_order($order5)));
            $recordReceipt->handle(new RecordReceiptCommand($order5, $clock->now(), 5_200, 'cash', ReceiptEntryType::Collection, 'Encaissement E2E rattrapage S5 (recette non corrigée)', (int) $adminIds[0]));
            $assert('S5 : registre non corrigé au départ (52 €)', 5_200 === $orderNet($order5), 'net=' . $orderNet($order5));

            $fix($order5);
            $assert('S5 : fee inchangé (−5 €)', -500 === $volumeFeeCents($order5), 'fee=' . $volumeFeeCents($order5));
            $assert('S5 : registre réconcilié à 47 € (reprise réussie)', 4_700 === $orderNet($order5), 'net=' . $orderNet($order5));

            // ===== Scénario 6 : recette DÉJÀ juste (47 €) mais fee manquant côté
            // commande. On pose le fee SANS re-rembourser (pas de sur-remboursement, #2). =====
            $order6 = $makeSaleOrder('completed', static function (WC_Order $o): void {
            });
            $recordReceipt->handle(new RecordReceiptCommand($order6, $clock->now(), 4_700, 'cash', ReceiptEntryType::Collection, 'Encaissement E2E rattrapage S6 (déjà corrigé)', (int) $adminIds[0]));
            $receiptRowsBefore6 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $order6));

            $fix($order6);
            $assert('S6 : fee de volume −5 € posé', -500 === $volumeFeeCents($order6), 'fee=' . $volumeFeeCents($order6));
            $assert('S6 : commande à 47 €', 4_700 === $cents(wc_get_order($order6)->get_total()), 'total=' . $cents(wc_get_order($order6)->get_total()));
            $assert('S6 : registre inchangé à 47 € (aucun sur-remboursement)', 4_700 === $orderNet($order6), 'net=' . $orderNet($order6));
            $receiptRowsAfter6 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $order6));
            $assert('S6 : aucune contre-passe écrite (une seule ligne de recette)', $receiptRowsBefore6 === $receiptRowsAfter6 && 1 === $receiptRowsAfter6, 'avant=' . $receiptRowsBefore6 . ' après=' . $receiptRowsAfter6);

            // ===== Scénario 4 : commande ANNULÉE — non rattrapable. Même critère
            // (isCorrectable) pour la métabox (bouton masqué) et l'audit (non listée). =====
            $order4 = $makeSaleOrder('cancelled', static function (WC_Order $o): void {
            });
            $assert('S4 : commande annulée déclarée non-corrigeable', ! WooCommerceOrderVolumeDiscountWriter::isCorrectable(wc_get_order($order4)));
            $assert('S4 : commande annulée absente de l’audit', ! $auditLists($order4));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('user_has_cap', $grantCap);
            $_POST = [];
            // Le vrai chemin admin + le dépôt de recettes AUDITÉ écrivent au journal
            // d'activité (création/édition/note de commande, backfill, contre-passe).
            // On les retire par référence — sur la commande (object_type=order) et sur
            // la recette (object_type=receipt) — pour ne laisser AUCUN orphelin en prod.
            $activityTable = $pilotageSchema->activityTableName();
            $receiptIds = [];
            foreach ($orderIds as $oid) {
                foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $oid)) as $rid) {
                    $receiptIds[] = (int) $rid;
                }
            }
            foreach ($orderIds as $oid) {
                $wpdb->delete($activityTable, ['object_type' => 'order', 'object_id' => $oid], ['%s', '%d']);
            }
            foreach ($receiptIds as $rid) {
                $wpdb->delete($activityTable, ['object_type' => 'receipt', 'object_id' => $rid], ['%s', '%d']);
            }
            foreach ($orderIds as $oid) {
                $wpdb->delete($pilotageSchema->tableName(), ['order_id' => $oid], ['%d']);
                $order = wc_get_order($oid);
                if ($order instanceof WC_Order) {
                    $order->delete(true);
                }
            }
            foreach ($productIds as $pid) {
                wp_delete_post($pid, true);
            }
            $receiptsLeft = 0;
            $activityLeft = 0;
            foreach ($orderIds as $oid) {
                $receiptsLeft += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $pilotageSchema->tableName() . ' WHERE order_id = %d', $oid));
                $activityLeft += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $activityTable . ' WHERE object_type = %s AND object_id = %d', 'order', $oid));
            }
            foreach ($receiptIds as $rid) {
                $activityLeft += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $activityTable . ' WHERE object_type = %s AND object_id = %d', 'receipt', $rid));
            }
            $cleanup = (0 === $receiptsLeft && 0 === $activityLeft)
                ? 'ok (aucune recette ni activité résiduelle)'
                : ($receiptsLeft . ' recette(s) / ' . $activityLeft . ' activité(s) résiduelle(s) !');
            $assert('Nettoyage : aucune recette résiduelle', 0 === $receiptsLeft);
            $assert('Nettoyage : aucune activité résiduelle (journal propre)', 0 === $activityLeft);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
