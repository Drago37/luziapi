<?php

/**
 * Cœur partagé du test e2e « Ajouter un pot offert à une commande existante »
 * (bloc de la fiche commande).
 *
 * Inclus par le wrapper local (WP-CLI, `make e2e-offered-pot-local`) et par le
 * wrapper prod à jeton (`scripts/e2e-offered-pot-prod.sh`). Ce fichier ne fait que
 * DÉFINIR la fonction ; il n'exécute rien à l'inclusion.
 *
 * Il pilote le VRAI chemin admin : contexte administrateur + nonce + `$_POST`, puis
 * appel de `luziapi_save_admin_order_workflow()` (le handler réellement branché sur
 * `woocommerce_process_shop_order_meta`), qui ajoute la ligne offerte à 0 €, décompte
 * le stock du seul nouvel item et — pour la fidélité — émet
 * `luziapi_loyalty_order_lines_changed` (recalcul → consommation de l'avantage).
 * On vérifie aussi que ces hooks sont câblés. C'est la couverture que l'unitaire ne
 * peut pas donner.
 *
 * Sûr en prod : produit masqué + commande de test isolés, statut posé via set_status
 * (donc AUCUN e-mail, AUCUNE recette), `pre_wp_mail` coupé par ceinture, et tout est
 * supprimé en `finally`. Le montant de la commande n'est jamais modifié (lignes 0 €).
 */

declare(strict_types=1);

use LuziApi\Loyalty\Application\Command\ReconcileOrderLoyalty\ReconcileOrderLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceLoyaltyEarningSubscriber;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressClock;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressIdGenerator;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! function_exists('luziapi_e2e_offered_pot_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_offered_pot_run(): array
    {
        add_filter('pre_wp_mail', '__return_false', 999);

        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        // 15 pots éligibles = 1 avantage (POTS_PER_REWARD).
        $potsForOneReward = 15;

        global $wpdb;
        $schema = new LoyaltySchemaManager($wpdb);
        $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
        $subscriber = new WooCommerceLoyaltyEarningSubscriber(
            new ReconcileOrderLoyaltyHandler($ledger, new WordPressClock(), new WordPressIdGenerator()),
            new WooCommerceEligiblePotCounter(),
            new WooCommerceOrderIdentityResolver(),
            new \Psr\Log\NullLogger(),
        );

        $productId = 0;
        $orderId = 0;
        $key = '';
        $email = 'e2e-offered-' . bin2hex(random_bytes(5)) . '@example.test';

        $grantCap = static function (array $allcaps): array {
            $allcaps['edit_shop_orders'] = true;

            return $allcaps;
        };

        // Compte les pots (quantité) marqués d'une méta de ligne sur la commande.
        $countMarked = static function (int $orderId, string $meta): int {
            $order = wc_get_order($orderId);
            $total = 0;
            foreach ($order instanceof WC_Order ? $order->get_items() : [] as $item) {
                if ('yes' === (string) $item->get_meta($meta)) {
                    $total += (int) $item->get_quantity();
                }
            }

            return $total;
        };
        $orderTotal = static fn (int $orderId): float => (float) wc_get_order($orderId)->get_total();
        $stockOf = static fn (int $productId): int => (int) wc_get_product($productId)->get_stock_quantity();

        try {
            $adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if ([] === $adminIds) {
                throw new \RuntimeException('Aucun administrateur pour simuler la sauvegarde de la fiche commande.');
            }
            wp_set_current_user((int) $adminIds[0]);
            add_filter('user_has_cap', $grantCap);

            $pot = new WC_Product_Simple();
            $pot->set_name('E2E — pot offert (test, à supprimer)');
            $pot->set_status('publish');
            $pot->set_catalog_visibility('hidden');
            $pot->set_regular_price('12');
            $pot->set_price('12');
            $pot->set_manage_stock(true);
            $pot->set_stock_quantity(100);
            $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
            $productId = (int) $pot->save();

            // Commande de base : assez de pots pour acquérir 1 avantage fidélité.
            $order = wc_create_order(['status' => 'pending']);
            $order->set_billing_first_name('E2E');
            $order->set_billing_email($email);
            $order->set_billing_phone('0600000000');
            $order->add_product(wc_get_product($productId), $potsForOneReward);
            $order->calculate_totals();
            $order->set_status('completed'); // set_status : ne déclenche PAS les hooks.
            $order->save();
            $orderId = (int) $order->get_id();
            $key = LoyaltyIdentity::fromContact($email, '0600000000')?->key ?? '';

            $subscriber->reconcile($orderId, wc_get_order($orderId));
            $assert('Base : ' . $potsForOneReward . ' pots crédités', $potsForOneReward === $ledger->orderTotals($orderId)['pots']);
            $assert('Base : 1 avantage disponible', 1 === luziapi_order_available_rewards(wc_get_order($orderId)));

            // Câblage réel.
            $assert(
                'Câblage : save handler branché (woocommerce_process_shop_order_meta, prio 20)',
                20 === has_action('woocommerce_process_shop_order_meta', 'luziapi_save_admin_order_workflow'),
            );
            $assert(
                'Câblage : un abonné écoute luziapi_loyalty_order_lines_changed',
                false !== has_action('luziapi_loyalty_order_lines_changed'),
            );

            $nonce = wp_create_nonce('luziapi_save_order_workflow');
            $totalRef = $orderTotal($orderId);

            // 1) Pot offert « geste commercial » : ligne 0 €, hors fidélité, stock -1.
            $stockBefore = $stockOf($productId);
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_offer_product'        => (string) $productId,
                'luziapi_offer_qty'            => '1',
                'luziapi_offer_type'           => 'gift',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert('Geste : 1 ligne offerte ajoutée', 1 === $countMarked($orderId, WooCommerceEligiblePotCounter::OFFERT_LINE_META));
            $assert('Geste : aucune ligne fidélité', 0 === $countMarked($orderId, WooCommerceEligiblePotCounter::REWARD_LINE_META));
            $assert('Geste : montant inchangé', abs($orderTotal($orderId) - $totalRef) < 0.0001);
            $assert('Geste : stock décompté (-1)', $stockBefore - 1 === $stockOf($productId));
            $assert('Geste : pots fidélité inchangés (' . $potsForOneReward . ')', $potsForOneReward === $ledger->orderTotals($orderId)['pots']);
            $assert('Geste : avantage toujours disponible (1)', 1 === luziapi_order_available_rewards(wc_get_order($orderId)));

            // 2) Pot offert « fidélité » : ligne 0 €, consomme l'avantage, stock -1.
            $stockBefore = $stockOf($productId);
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_offer_product'        => (string) $productId,
                'luziapi_offer_qty'            => '1',
                'luziapi_offer_type'           => 'loyalty',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert('Fidélité : 1 ligne fidélité ajoutée', 1 === $countMarked($orderId, WooCommerceEligiblePotCounter::REWARD_LINE_META));
            $assert('Fidélité : montant inchangé', abs($orderTotal($orderId) - $totalRef) < 0.0001);
            $assert('Fidélité : stock décompté (-1)', $stockBefore - 1 === $stockOf($productId));
            $assert('Fidélité : avantage consommé (0 restant)', 0 === luziapi_order_available_rewards(wc_get_order($orderId)));

            // 3) Deuxième pot fidélité alors qu'aucun avantage : REFUSÉ.
            $rewardsBefore = $countMarked($orderId, WooCommerceEligiblePotCounter::REWARD_LINE_META);
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_offer_product'        => (string) $productId,
                'luziapi_offer_qty'            => '1',
                'luziapi_offer_type'           => 'loyalty',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert('Fidélité sans avantage : refusée (aucune ligne ajoutée)', $rewardsBefore === $countMarked($orderId, WooCommerceEligiblePotCounter::REWARD_LINE_META));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('user_has_cap', $grantCap);
            $_POST = [];
            if ('' !== $key) {
                $wpdb->delete($schema->ledgerTableName(), ['customer_key' => $key], ['%s']);
            }
            if ($orderId > 0) {
                $order = wc_get_order($orderId);
                if ($order instanceof WC_Order) {
                    $order->delete(true);
                }
            }
            if ($productId > 0) {
                wp_delete_post($productId, true);
            }
            $left = '' !== $key
                ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $schema->ledgerTableName() . ' WHERE customer_key = %s', $key))
                : 0;
            $cleanup = 0 === $left ? 'ok (aucune ligne résiduelle)' : ($left . ' ligne(s) résiduelle(s) !');
            $assert('Nettoyage : aucune ligne de journal résiduelle', 0 === $left);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
