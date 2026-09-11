<?php

/**
 * Cœur partagé du test e2e de la case « Exclure de la fidélité » (fiche commande).
 *
 * Inclus par le wrapper local (WP-CLI, `make e2e-exclusion-local`) et par le
 * wrapper prod à jeton (`scripts/e2e-exclusion-prod.sh`). Ce fichier ne fait que
 * DÉFINIR la fonction ; il n'exécute rien à l'inclusion.
 *
 * Il pilote le VRAI chemin admin : après avoir posé un contexte administrateur,
 * un nonce et `$_POST`, il appelle `luziapi_save_admin_order_workflow()` (le
 * handler réellement branché sur `woocommerce_process_shop_order_meta`), qui écrit
 * la méta d'exclusion puis émet `luziapi_loyalty_exclusion_changed` — action que
 * l'abonné fidélité enregistré recalcule. On vérifie aussi que ces hooks sont
 * bien câblés. C'est la couverture que l'unitaire ne peut pas donner.
 *
 * Sûr en prod : produit masqué + commande de test isolés, statut posé via
 * set_status (donc AUCUN e-mail, AUCUNE recette, AUCUN mouvement de complétion),
 * `pre_wp_mail` coupé par ceinture, et tout est supprimé en `finally`.
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

if (! function_exists('luziapi_e2e_exclusion_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_exclusion_run(): array
    {
        add_filter('pre_wp_mail', '__return_false', 999);

        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        global $wpdb;
        $schema = new LoyaltySchemaManager($wpdb);
        $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
        $counter = new WooCommerceEligiblePotCounter();
        $subscriber = new WooCommerceLoyaltyEarningSubscriber(
            new ReconcileOrderLoyaltyHandler($ledger, new WordPressClock(), new WordPressIdGenerator()),
            $counter,
            new WooCommerceOrderIdentityResolver(),
            new \Psr\Log\NullLogger(),
        );

        $productId = 0;
        $orderId = 0;
        $key = '';
        $email = 'e2e-exclusion-' . bin2hex(random_bytes(5)) . '@example.test';

        // Garantit `edit_shop_orders` le temps du test (un vrai opérateur l'a en
        // prod ; l'install de dev locale ne provisionne pas toujours les rôles WC).
        $grantCap = static function (array $allcaps): array {
            $allcaps['edit_shop_orders'] = true;

            return $allcaps;
        };

        try {
            // Contexte admin : le save handler exige edit_shop_orders + nonce valide.
            $adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            if ([] === $adminIds) {
                throw new \RuntimeException('Aucun administrateur pour simuler la sauvegarde de la fiche commande.');
            }
            wp_set_current_user((int) $adminIds[0]);
            add_filter('user_has_cap', $grantCap);

            $pot = new WC_Product_Simple();
            $pot->set_name('E2E — exclusion fidélité (test, à supprimer)');
            $pot->set_status('publish');
            $pot->set_catalog_visibility('hidden');
            $pot->set_regular_price('12');
            $pot->set_price('12');
            $pot->set_manage_stock(true);
            $pot->set_stock_quantity(50);
            $pot->update_meta_data(WooCommerceEligiblePotCounter::PRODUCT_ELIGIBLE_META, 'yes');
            $productId = (int) $pot->save();

            $order = wc_create_order(['status' => 'pending']);
            $order->set_billing_first_name('E2E');
            $order->set_billing_email($email);
            $order->set_billing_phone('0600000000');
            $order->add_product(wc_get_product($productId), 3);
            $order->calculate_totals();
            $order->set_status('completed'); // set_status : ne déclenche PAS les hooks.
            $order->save();
            $orderId = (int) $order->get_id();
            $key = LoyaltyIdentity::fromContact($email, '0600000000')?->key ?? '';

            // Crédit de base, comme au passage « Terminée ».
            $subscriber->reconcile($orderId, wc_get_order($orderId));
            $assert('Crédit de base : 3 pots', 3 === $ledger->orderTotals($orderId)['pots']);

            // Le câblage réel doit être présent (sinon la fonctionnalité ne se déclenche pas).
            $assert(
                'Câblage : save handler branché sur woocommerce_process_shop_order_meta (prio 20)',
                20 === has_action('woocommerce_process_shop_order_meta', 'luziapi_save_admin_order_workflow'),
            );
            $assert(
                'Câblage : un abonné écoute luziapi_loyalty_exclusion_changed',
                false !== has_action('luziapi_loyalty_exclusion_changed'),
            );

            $nonce = wp_create_nonce('luziapi_save_order_workflow');

            // 1) Coche « Exclure » via le VRAI save handler → méta posée → action → recalcul.
            $_POST = [
                'luziapi_order_workflow_nonce' => $nonce,
                'luziapi_exclude_from_loyalty' => 'yes',
            ];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert(
                'Exclusion cochée : méta posée',
                'yes' === (string) wc_get_order($orderId)->get_meta(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META),
            );
            $assert('Exclusion cochée : pots recalculés à 0', 0 === $ledger->orderTotals($orderId)['pots']);

            // 2) Décoche (case absente du POST) → méta retirée → action → réattribution.
            $_POST = ['luziapi_order_workflow_nonce' => $nonce];
            luziapi_save_admin_order_workflow($orderId, wc_get_order($orderId));
            $assert(
                'Exclusion décochée : méta retirée',
                '' === (string) wc_get_order($orderId)->get_meta(WooCommerceLoyaltyEarningSubscriber::LOYALTY_EXCLUDED_META),
            );
            $assert('Exclusion décochée : pots réattribués (3)', 3 === $ledger->orderTotals($orderId)['pots']);
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
